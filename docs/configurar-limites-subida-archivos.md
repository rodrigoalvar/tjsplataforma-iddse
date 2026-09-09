# Configurar Límites de Subida de Archivos Grandes

Este documento explica cómo configurar el servidor para permitir la subida de archivos de audio grandes (hasta 100MB) para la funcionalidad de transcripción de AI Informes.

## Diagnóstico

Primero, verifica los límites actuales accediendo a:
```
https://tu-dominio.com/api/check-upload-limits.php
```

Este script mostrará:
- Límites actuales de PHP
- Configuración del servidor web
- Recomendaciones específicas

## Configuración por Servidor Web

### Nginx

El error 413 (Content Too Large) generalmente viene de Nginx, que tiene un límite por defecto de 1MB.

#### 1. Editar configuración del sitio

```bash
sudo nano /etc/nginx/sites-available/tu-sitio.conf
```

#### 2. Agregar dentro del bloque `server`:

```nginx
server {
    listen 80;
    server_name plataforma.iddse.com.ar;
    
    # Aumentar límite de tamaño de cuerpo de solicitud
    client_max_body_size 100M;
    
    # ... resto de la configuración
}
```

#### 3. Verificar y recargar

```bash
# Verificar que la configuración sea válida
sudo nginx -t

# Si todo está bien, recargar Nginx
sudo systemctl reload nginx
```

#### 4. Verificar en logs (opcional)

```bash
sudo tail -f /var/log/nginx/error.log
```

### Apache

#### Opción 1: Usar .htaccess (Recomendado)

El archivo `.htaccess` ya está creado en `/var/www/tjsiddse/api/.htaccess` con la siguiente configuración:

```apache
php_value upload_max_filesize 100M
php_value post_max_size 100M
php_value memory_limit 256M
php_value max_execution_time 600
```

**Nota:** Asegúrate de que:
- `AllowOverride` esté configurado en el virtual host
- `mod_php` o `php-fpm` esté habilitado

#### Opción 2: Configuración del Virtual Host

Si `.htaccess` no funciona, agrega en la configuración del virtual host:

```apache
<Directory "/var/www/tjsiddse">
    LimitRequestBody 104857600  # 100MB en bytes
    AllowOverride All
</Directory>
```

Luego reinicia Apache:

```bash
sudo systemctl restart apache2
```

## Configuración de PHP

Los límites de PHP ya están configurados en `api/ai-informes.php`:

```php
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '600');
```

Si necesitas cambiar estos valores globalmente, edita `php.ini`:

```bash
sudo nano /etc/php/8.x/fpm/php.ini  # Para PHP-FPM
# o
sudo nano /etc/php/8.x/apache2/php.ini  # Para Apache mod_php
```

Busca y modifica:
```ini
upload_max_filesize = 100M
post_max_size = 100M
memory_limit = 256M
max_execution_time = 600
```

Luego reinicia el servicio:
```bash
sudo systemctl restart php8.x-fpm  # Para PHP-FPM
# o
sudo systemctl restart apache2  # Para Apache mod_php
```

## Verificación

1. Accede a `https://tu-dominio.com/api/check-upload-limits.php`
2. Verifica que todos los límites sean de al menos 100MB
3. Intenta subir un archivo de audio grande desde la interfaz

## Solución de Problemas

### Error 413 persiste después de configurar Nginx

1. Verifica que editaste el archivo correcto:
   ```bash
   sudo nginx -T | grep client_max_body_size
   ```

2. Asegúrate de que la configuración esté dentro del bloque `server` correcto

3. Verifica que no haya otra directiva `client_max_body_size` que lo sobrescriba

4. Revisa los logs:
   ```bash
   sudo tail -f /var/log/nginx/error.log
   ```

### Error 413 con Apache

1. Verifica que `AllowOverride` esté configurado:
   ```apache
   <Directory "/var/www/tjsiddse">
       AllowOverride All
   </Directory>
   ```

2. Verifica que `mod_php` o `php-fpm` esté funcionando:
   ```bash
   php -v
   ```

3. Verifica permisos del archivo `.htaccess`:
   ```bash
   ls -la /var/www/tjsiddse/api/.htaccess
   ```

### Los límites de PHP no se aplican

1. Verifica que `ini_set()` esté permitido (no está en `php.ini` con `disable_functions`)

2. Verifica los límites reales:
   ```php
   <?php
   phpinfo();
   ?>
   ```

3. Si usas PHP-FPM, verifica la configuración del pool:
   ```bash
   sudo nano /etc/php/8.x/fpm/pool.d/www.conf
   ```

## Límites Recomendados

Para transcripciones de audio:

- **upload_max_filesize**: 100M (mínimo recomendado)
- **post_max_size**: 100M (debe ser >= upload_max_filesize)
- **memory_limit**: 256M (para procesamiento)
- **max_execution_time**: 600 segundos (10 minutos para archivos largos)
- **client_max_body_size** (Nginx): 100M
- **LimitRequestBody** (Apache): 104857600 bytes (100MB)

## Notas Importantes

1. **El límite del servidor web debe ser >= al límite de PHP**
2. **post_max_size debe ser >= upload_max_filesize**
3. **Después de cambiar la configuración, siempre reinicia/recarga el servicio**
4. **Los cambios en php.ini requieren reiniciar PHP-FPM o Apache**

## Referencias

- [Nginx: client_max_body_size](https://nginx.org/en/docs/http/ngx_http_core_module.html#client_max_body_size)
- [Apache: LimitRequestBody](https://httpd.apache.org/docs/2.4/mod/core.html#limitrequestbody)
- [PHP: upload_max_filesize](https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize)
