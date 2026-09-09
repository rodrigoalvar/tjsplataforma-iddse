# Configurar límites de PHP para Nginx + PHP-FPM

Cuando usas Nginx con PHP-FPM, el archivo `.htaccess` **NO funciona** porque Nginx no procesa archivos `.htaccess`. Los límites de PHP deben configurarse directamente en `php.ini` o en la configuración de PHP-FPM.

## Solución Rápida (Script Automático)

Si tienes acceso sudo, puedes usar el script automático:

```bash
sudo bash /var/www/tjsiddse/configurar-limites-php-fpm.sh
```

Este script:
- Detecta automáticamente tu versión de PHP
- Crea el archivo de configuración necesario
- Reinicia PHP-FPM
- Verifica que todo esté correcto

## Problema

Si ves errores como:
- "El archivo excede el límite de upload_max_filesize (2M)"
- "post_max_size es muy pequeño"

Esto significa que los valores en `php.ini` son demasiado bajos.

## Solución

### Opción 1: Editar php.ini directamente

1. Encuentra el archivo `php.ini` que está usando PHP:
   ```bash
   php -i | grep "Loaded Configuration File"
   ```

2. Edita el archivo `php.ini`:
   ```bash
   sudo nano /etc/php/8.1/fpm/php.ini  # Ajusta la versión de PHP según tu sistema
   ```

3. Busca y modifica estas líneas:
   ```ini
   upload_max_filesize = 500M
   post_max_size = 500M
   memory_limit = 512M
   max_execution_time = 1800
   max_input_time = 1800
   ```

4. Reinicia PHP-FPM:
   ```bash
   sudo systemctl restart php8.1-fpm  # Ajusta la versión según tu sistema
   ```

### Opción 2: Crear un archivo de configuración personalizado (recomendado)

1. Crea un archivo de configuración específico para tu aplicación:
   ```bash
   sudo nano /etc/php/8.1/fpm/conf.d/99-custom-upload-limits.ini
   ```

2. Agrega estas líneas:
   ```ini
   ; Límites personalizados para subida de archivos grandes
   upload_max_filesize = 500M
   post_max_size = 500M
   memory_limit = 512M
   max_execution_time = 1800
   max_input_time = 1800
   ```

3. Reinicia PHP-FPM:
   ```bash
   sudo systemctl restart php8.1-fpm
   ```

### Opción 3: Configurar en el pool de PHP-FPM

1. Edita el archivo de configuración del pool (generalmente en `/etc/php/8.1/fpm/pool.d/www.conf`):
   ```bash
   sudo nano /etc/php/8.1/fpm/pool.d/www.conf
   ```

2. Agrega estas líneas en la sección `[www]`:
   ```ini
   php_admin_value[upload_max_filesize] = 500M
   php_admin_value[post_max_size] = 500M
   php_admin_value[memory_limit] = 512M
   php_admin_value[max_execution_time] = 1800
   php_admin_value[max_input_time] = 1800
   ```

3. Reinicia PHP-FPM:
   ```bash
   sudo systemctl restart php8.1-fpm
   ```

## Verificar la configuración

Después de hacer los cambios, verifica que se aplicaron correctamente:

```bash
php -r "echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . PHP_EOL; echo 'post_max_size: ' . ini_get('post_max_size') . PHP_EOL;"
```

Deberías ver:
```
upload_max_filesize: 500M
post_max_size: 500M
```

## Nota sobre Nginx

Asegúrate de que Nginx también tenga configurado `client_max_body_size`:

```nginx
client_max_body_size 500M;
```

Y reinicia Nginx:
```bash
sudo systemctl reload nginx
```

## Solución de problemas

Si después de cambiar `php.ini` los valores no cambian:

1. Verifica que estás editando el `php.ini` correcto (puede haber múltiples instalaciones de PHP)
2. Asegúrate de reiniciar PHP-FPM después de los cambios
3. Verifica que no haya otros archivos de configuración sobrescribiendo los valores
4. Revisa los logs de PHP-FPM para errores:
   ```bash
   sudo tail -f /var/log/php8.1-fpm.log
   ```
