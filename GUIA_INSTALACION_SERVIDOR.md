# Guía de Instalación del Sistema en Servidor

Esta guía detalla los pasos necesarios para instalar el Portal de Estudios Médicos en un servidor, incluyendo la configuración de múltiples instancias con bases de datos separadas.

---

## 📋 Tabla de Contenidos

1. [Requisitos Previos](#requisitos-previos)
2. [Preparación del Servidor](#preparación-del-servidor)
3. [Instalación de Archivos](#instalación-de-archivos)
4. [Configuración de Base de Datos](#configuración-de-base-de-datos)
5. [Configuración de URLs y Endpoints](#configuración-de-urls-y-endpoints)
6. [Configuración de Orthanc (PACS)](#configuración-de-orthanc-pacs)
7. [Configuración de Permisos](#configuración-de-permisos)
8. [Instalación Múltiple (Múltiples Instancias)](#instalación-múltiple-múltiples-instancias)
9. [Verificación y Pruebas](#verificación-y-pruebas)
10. [Solución de Problemas](#solución-de-problemas)

---

## 🔧 Requisitos Previos

### Software Necesario

- **Servidor Web**: Apache 2.4+ o Nginx
- **PHP**: Versión 7.4 o superior (recomendado 8.0+)
- **Base de Datos**: MySQL 5.7+ o MariaDB 10.3+
- **Extensiones PHP requeridas**:
  - `pdo_mysql`
  - `curl`
  - `json`
  - `mbstring`
  - `gd` o `imagick` (para procesamiento de imágenes)
  - `zip` (para exportación)
  - `openssl` (para seguridad)

### Permisos del Sistema

- Acceso SSH o FTP al servidor
- Permisos para crear bases de datos
- Permisos de escritura en directorios de uploads y logs

---

## 🖥️ Preparación del Servidor

### 1. Crear Directorio del Proyecto

```bash
# Ejemplo para Apache
sudo mkdir -p /var/www/html/portal_estudios
sudo chown -R www-data:www-data /var/www/html/portal_estudios

# O para Nginx
sudo mkdir -p /var/www/portal_estudios
sudo chown -R nginx:nginx /var/www/portal_estudios
```

### 2. Configurar Permisos

```bash
# Dar permisos de escritura a directorios necesarios
sudo chmod -R 755 /var/www/html/portal_estudios
sudo chmod -R 775 /var/www/html/portal_estudios/uploads
sudo chmod -R 775 /var/www/html/portal_estudios/logs
```

---

## 📦 Instalación de Archivos

### 1. Subir Archivos al Servidor

Subir todos los archivos del proyecto al directorio creado usando:
- **FTP/SFTP**: FileZilla, WinSCP, etc.
- **SCP**: `scp -r PORTAL_ESTUDIOS/* usuario@servidor:/var/www/html/portal_estudios/`
- **Git**: Si el proyecto está en un repositorio Git

### 2. Estructura de Directorios

Asegurarse de que la estructura de directorios sea correcta:

```
portal_estudios/
├── api/
├── assets/
├── classes/
├── components/
├── config/
├── css/
├── database/
├── js/
├── libs/
├── logs/
├── middleware/
├── uploads/
└── vendor/ (si usa Composer)
```

---

## 🗄️ Configuración de Base de Datos

### 1. Crear Base de Datos

Para cada instancia del sistema, crear una base de datos separada:

```sql
-- Ejemplo para instancia principal
CREATE DATABASE portal_estudios_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ejemplo para segunda instancia
CREATE DATABASE portal_estudios_clinica2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ejemplo para tercera instancia
CREATE DATABASE portal_estudios_hospital CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Crear Usuario de Base de Datos

```sql
-- Crear usuario (ajustar según necesidades de seguridad)
CREATE USER 'portal_user'@'localhost' IDENTIFIED BY 'contraseña_segura_aqui';
GRANT ALL PRIVILEGES ON portal_estudios_main.* TO 'portal_user'@'localhost';
GRANT ALL PRIVILEGES ON portal_estudios_clinica2.* TO 'portal_user'@'localhost';
GRANT ALL PRIVILEGES ON portal_estudios_hospital.* TO 'portal_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Importar Estructura de Base de Datos

```bash
# Importar estructura base
mysql -u portal_user -p portal_estudios_main < database/tjsmedical.sql

# Importar scripts adicionales si es necesario
mysql -u portal_user -p portal_estudios_main < database/crear_todas_columnas_pacs.sql
mysql -u portal_user -p portal_estudios_main < database/informes_audios.sql
# ... otros scripts según necesidad
```

### 4. Configurar Conexión a Base de Datos

**Archivo a modificar**: `config/database.php`

```php
<?php
if (!class_exists('Database')) {
    class Database {
        // ⚠️ CAMBIAR ESTOS VALORES PARA CADA INSTANCIA
        private $host = 'localhost';                    // IP o hostname del servidor MySQL
        private $db_name = 'portal_estudios_main';      // ⚠️ Nombre de la base de datos
        private $username = 'portal_user';              // ⚠️ Usuario de MySQL
        private $password = 'contraseña_segura_aqui';    // ⚠️ Contraseña de MySQL
        private $charset = 'utf8mb4';
        private $conn;
        
        // ... resto del código sin cambios
    }
}
?>
```

**⚠️ IMPORTANTE**: Para cada instancia del sistema, crear una copia del proyecto o usar un archivo de configuración diferente que apunte a una base de datos distinta.

---

## 🌐 Configuración de URLs y Endpoints

### URLs que Deben Cambiarse

#### 1. Configuración de Orthanc (PACS)

**Archivo**: `api/config/orthanc_config.php`

```php
<?php
class OrthancConfig {
    private static $config = [
        'server' => [
            'host' => '100.123.191.72',        // ⚠️ IP del servidor Orthanc
            'port' => 8044,                      // ⚠️ Puerto de Orthanc
            'protocol' => 'http',                // ⚠️ http o https
            'username' => 'orthanc',              // ⚠️ Usuario de Orthanc
            'password' => 'orthanc'               // ⚠️ Contraseña de Orthanc
        ],
        'viewer' => [
            // ⚠️ URL del visor DICOM - CAMBIAR ESTA URL
            'url' => 'https://demoportal.tanjousoft.com.ar/u-dicom-viewer/?studyId=',
            'study_id_param' => 'studyId'
        ],
        // ... resto de configuración
    ];
}
?>
```

**Valores a cambiar**:
- `host`: IP o dominio del servidor Orthanc
- `port`: Puerto donde corre Orthanc (por defecto 8042 o 8044)
- `protocol`: `http` o `https` según configuración
- `username` y `password`: Credenciales de Orthanc
- `viewer.url`: URL completa del visor DICOM web

#### 2. URLs en Archivos JavaScript

Los archivos JavaScript usan principalmente rutas relativas que se adaptan automáticamente, pero hay algunas URLs hardcodeadas que pueden necesitar ajuste:

**Archivo**: `assets/js/informes-manager.js` (línea 1)
```javascript
// Si hay URLs hardcodeadas, cambiarlas
const INFORMES_BASE_PREFIX = 'http://localhost:8080';  // ⚠️ Cambiar si es necesario
```

**Archivo**: `assets/js/dicom.js` (líneas 4-7)
```javascript
config: {
    pacsURL: 'https://tuc-i310100.tail186bcc.ts.net',           // ⚠️ URL del PACS
    viewerURL: 'https://tuc-i310100.tail186bcc.ts.net/?studyId=', // ⚠️ URL del visor
    apiEndpoint: '/api/studies'
}
```

**Archivo**: `assets/js/estudios-manager.js` (línea 6918-6929)
```javascript
getServerBaseUrl() {
    // Si hay una URL externa configurada, cambiarla aquí
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        return this.externalServerUrl;  // ⚠️ Configurar esta propiedad si es necesario
    }
    return window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
}
```

#### 3. Configuración de Evolution API (WhatsApp) - Si se usa

**Archivo**: `api/classes/EvolutionAPI.php` (línea 28)
```php
public function __construct($config = []) {
    $this->baseUrl = rtrim($config['base_url'] ?? 'http://192.168.10.155:9080', '/');  // ⚠️ Cambiar
    $this->instanceName = $config['instance_name'] ?? 'micel';  // ⚠️ Cambiar
    // ... resto
}
```

### URLs que NO Necesitan Cambio

Los siguientes archivos usan rutas relativas y se adaptan automáticamente:
- `assets/js/informes-manager.js` - Usa `window.location.pathname`
- `assets/js/pacientes-manager.js` - Usa rutas relativas `api/pacientes/`
- `assets/js/estudios-manager.js` - Usa rutas relativas `api/`
- `assets/js/dashboard-with-permissions.js` - Detecta automáticamente la ruta

---

## 🏥 Configuración de Orthanc (PACS)

### 1. Verificar Conexión con Orthanc

Crear un archivo de prueba: `test_orthanc_connection.php`

```php
<?php
require_once 'api/config/orthanc_config.php';

if (OrthancConfig::testConnection()) {
    echo "✅ Conexión con Orthanc exitosa";
} else {
    echo "❌ Error de conexión con Orthanc";
}
?>
```

### 2. Configurar Firewall

Asegurarse de que el servidor pueda acceder al puerto de Orthanc:

```bash
# Ejemplo para UFW (Ubuntu)
sudo ufw allow 8044/tcp

# O para firewalld (CentOS/RHEL)
sudo firewall-cmd --add-port=8044/tcp --permanent
sudo firewall-cmd --reload
```

---

## 🔐 Configuración de Permisos

### 1. Permisos de Archivos

```bash
# Directorio raíz
find /var/www/html/portal_estudios -type d -exec chmod 755 {} \;
find /var/www/html/portal_estudios -type f -exec chmod 644 {} \;

# Directorios que necesitan escritura
chmod -R 775 /var/www/html/portal_estudios/uploads
chmod -R 775 /var/www/html/portal_estudios/logs
chmod -R 775 /var/www/html/portal_estudios/api
```

### 2. Propietario de Archivos

```bash
# Para Apache
sudo chown -R www-data:www-data /var/www/html/portal_estudios

# Para Nginx
sudo chown -R nginx:nginx /var/www/html/portal_estudios
```

---

## 🔄 Instalación Múltiple (Múltiples Instancias)

Para tener múltiples instancias del sistema en el mismo servidor, cada una con su propia base de datos:

### Opción 1: Múltiples Directorios (Recomendado)

```
/var/www/html/
├── portal_estudios_clinica1/
│   ├── config/
│   │   └── database.php  (apunta a BD: portal_estudios_clinica1)
│   └── ...
├── portal_estudios_clinica2/
│   ├── config/
│   │   └── database.php  (apunta a BD: portal_estudios_clinica2)
│   └── ...
└── portal_estudios_hospital/
    ├── config/
    │   └── database.php  (apunta a BD: portal_estudios_hospital)
    └── ...
```

**Pasos**:

1. **Crear directorios separados**:
```bash
sudo mkdir -p /var/www/html/portal_estudios_clinica1
sudo mkdir -p /var/www/html/portal_estudios_clinica2
sudo mkdir -p /var/www/html/portal_estudios_hospital
```

2. **Subir archivos a cada directorio** (o usar enlaces simbólicos para archivos compartidos)

3. **Configurar bases de datos separadas**:
```sql
CREATE DATABASE portal_estudios_clinica1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portal_estudios_clinica2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portal_estudios_hospital CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. **Configurar `config/database.php` en cada instancia**:
   - **Instancia 1**: `db_name = 'portal_estudios_clinica1'`
   - **Instancia 2**: `db_name = 'portal_estudios_clinica2'`
   - **Instancia 3**: `db_name = 'portal_estudios_hospital'`

5. **Importar estructura en cada base de datos**:
```bash
mysql -u portal_user -p portal_estudios_clinica1 < database/tjsmedical.sql
mysql -u portal_user -p portal_estudios_clinica2 < database/tjsmedical.sql
mysql -u portal_user -p portal_estudios_hospital < database/tjsmedical.sql
```

6. **Configurar Virtual Hosts en Apache** (o server blocks en Nginx):

**Apache** (`/etc/apache2/sites-available/portal_clinica1.conf`):
```apache
<VirtualHost *:80>
    ServerName clinica1.tudominio.com
    DocumentRoot /var/www/html/portal_estudios_clinica1
    
    <Directory /var/www/html/portal_estudios_clinica1>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/portal_clinica1_error.log
    CustomLog ${APACHE_LOG_DIR}/portal_clinica1_access.log combined
</VirtualHost>
```

**Nginx** (`/etc/nginx/sites-available/portal_clinica1`):
```nginx
server {
    listen 80;
    server_name clinica1.tudominio.com;
    root /var/www/html/portal_estudios_clinica1;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

7. **Habilitar sitios**:
```bash
# Apache
sudo a2ensite portal_clinica1.conf
sudo a2ensite portal_clinica2.conf
sudo systemctl reload apache2

# Nginx
sudo ln -s /etc/nginx/sites-available/portal_clinica1 /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Opción 2: Usar Variables de Entorno

Crear un archivo `.env` en cada instancia y modificar `config/database.php` para leerlo:

```php
// config/database.php
private $host = $_ENV['DB_HOST'] ?? 'localhost';
private $db_name = $_ENV['DB_NAME'] ?? 'TJSMEDICAL';
private $username = $_ENV['DB_USER'] ?? 'root';
private $password = $_ENV['DB_PASS'] ?? '';
```

---

## ✅ Verificación y Pruebas

### 1. Verificar Conexión a Base de Datos

Crear archivo: `test_db_connection.php`

```php
<?php
require_once 'config/database.php';

$db = getDBConnection();
if ($db) {
    echo "✅ Conexión a base de datos exitosa\n";
    echo "Base de datos: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";
} else {
    echo "❌ Error de conexión a base de datos\n";
}
?>
```

Acceder desde navegador: `http://tudominio.com/test_db_connection.php`

### 2. Verificar Estructura de Tablas

```sql
USE portal_estudios_main;
SHOW TABLES;

-- Verificar tablas principales
SELECT COUNT(*) as total FROM usuarios;
SELECT COUNT(*) as total FROM sesiones;
SELECT COUNT(*) as total FROM estudios;
```

### 3. Probar Login

1. Acceder a: `http://tudominio.com/login.html`
2. Intentar iniciar sesión con usuario administrador
3. Verificar que se crea la sesión correctamente

### 4. Verificar APIs

```bash
# Probar endpoint de estudios
curl http://tudominio.com/api/get_all_studies.php

# Probar endpoint de autenticación
curl http://tudominio.com/api/auth/validate-session-simple.php
```

### 5. Verificar Conexión con Orthanc

```bash
# Desde el servidor
curl -u orthanc:orthanc http://100.123.191.72:8044/system
```

---

## 🔧 Solución de Problemas

### Error: "No se puede conectar a la base de datos"

**Solución**:
1. Verificar credenciales en `config/database.php`
2. Verificar que MySQL esté corriendo: `sudo systemctl status mysql`
3. Verificar que el usuario tenga permisos: `SHOW GRANTS FOR 'portal_user'@'localhost';`
4. Verificar firewall: `sudo ufw status`

### Error: "404 Not Found" en rutas API

**Solución**:
1. Verificar configuración de `.htaccess` (Apache) o `nginx.conf` (Nginx)
2. Habilitar mod_rewrite: `sudo a2enmod rewrite`
3. Verificar permisos de archivos

### Error: "CORS" en navegador

**Solución**:
1. Verificar que las URLs en JavaScript sean correctas
2. Configurar headers CORS en el servidor si es necesario
3. Verificar que no haya URLs hardcodeadas apuntando a localhost

### Error: "No se puede conectar a Orthanc"

**Solución**:
1. Verificar configuración en `api/config/orthanc_config.php`
2. Verificar que Orthanc esté corriendo
3. Verificar firewall y red
4. Probar conexión manual: `curl -u usuario:password http://ip_orthanc:puerto/system`

### Error: "Permisos denegados" en uploads

**Solución**:
```bash
sudo chmod -R 775 /var/www/html/portal_estudios/uploads
sudo chown -R www-data:www-data /var/www/html/portal_estudios/uploads
```

---

## 📝 Checklist de Instalación

- [ ] Servidor web configurado (Apache/Nginx)
- [ ] PHP instalado con extensiones necesarias
- [ ] MySQL/MariaDB instalado y corriendo
- [ ] Base de datos creada
- [ ] Estructura de base de datos importada
- [ ] Archivos del proyecto subidos al servidor
- [ ] `config/database.php` configurado con credenciales correctas
- [ ] `api/config/orthanc_config.php` configurado
- [ ] URLs en JavaScript verificadas (si hay hardcodeadas)
- [ ] Permisos de archivos configurados
- [ ] Virtual hosts configurados (si múltiples instancias)
- [ ] Firewall configurado
- [ ] Conexión a base de datos probada
- [ ] Conexión a Orthanc probada
- [ ] Login funcionando
- [ ] APIs respondiendo correctamente

---

## 📞 Soporte

Para problemas adicionales, revisar:
- Logs del servidor: `/var/log/apache2/` o `/var/log/nginx/`
- Logs de PHP: `php.ini` → `error_log`
- Logs de la aplicación: `logs/` (si existen)

---

## 🔄 Actualizaciones Futuras

Cuando se actualice el sistema:

1. **Hacer backup de bases de datos**:
```bash
mysqldump -u portal_user -p portal_estudios_main > backup_$(date +%Y%m%d).sql
```

2. **Hacer backup de archivos**:
```bash
tar -czf backup_archivos_$(date +%Y%m%d).tar.gz /var/www/html/portal_estudios
```

3. **Subir nuevos archivos** (manteniendo `config/database.php` y otras configuraciones)

4. **Ejecutar scripts de migración** si existen:
```bash
mysql -u portal_user -p portal_estudios_main < database/nuevo_script.sql
```

---

**Última actualización**: 2024

