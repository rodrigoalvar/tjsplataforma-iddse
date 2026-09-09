# Ubicaciones de Configuración de PHP-FPM en Linux con Nginx

## Ubicaciones Principales

### 1. Archivo principal de configuración PHP-FPM
**Ubicación:** `/etc/php/[VERSION]/fpm/php.ini`

Ejemplo para PHP 8.1:
```bash
/etc/php/8.1/fpm/php.ini
```

**Cómo editarlo:**
```bash
sudo nano /etc/php/8.1/fpm/php.ini
```

Busca y modifica estas líneas:
```ini
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 1800
max_input_time = 1800
```

### 2. Directorio de configuración adicional (RECOMENDADO)
**Ubicación:** `/etc/php/[VERSION]/fpm/conf.d/`

Ejemplo para PHP 8.1:
```bash
/etc/php/8.1/fpm/conf.d/
```

**Ventaja:** Puedes crear archivos separados sin modificar el php.ini principal.

**Crear archivo personalizado:**
```bash
sudo nano /etc/php/8.1/fpm/conf.d/99-custom-upload-limits.ini
```

Contenido:
```ini
; Límites personalizados para subida de archivos grandes
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 1800
max_input_time = 1800
```

### 3. Configuración del pool de PHP-FPM
**Ubicación:** `/etc/php/[VERSION]/fpm/pool.d/www.conf`

Ejemplo para PHP 8.1:
```bash
/etc/php/8.1/fpm/pool.d/www.conf
```

**Cómo editarlo:**
```bash
sudo nano /etc/php/8.1/fpm/pool.d/www.conf
```

Busca la sección `[www]` y agrega:
```ini
[www]
php_admin_value[upload_max_filesize] = 500M
php_admin_value[post_max_size] = 500M
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 1800
php_admin_value[max_input_time] = 1800
```

## ¿Cuál método usar?

### Opción 1: Archivo en conf.d/ (RECOMENDADO) ⭐
- ✅ No modifica el php.ini principal
- ✅ Fácil de mantener y actualizar
- ✅ Se carga automáticamente
- ✅ Fácil de desactivar si es necesario

### Opción 2: php.ini principal
- ✅ Configuración centralizada
- ❌ Más difícil de mantener
- ❌ Se puede perder al actualizar PHP

### Opción 3: pool.d/www.conf
- ✅ Configuración específica por pool
- ✅ Útil si tienes múltiples pools
- ❌ Solo afecta al pool específico

## Pasos para Configurar (Método Recomendado)

1. **Crear archivo de configuración:**
   ```bash
   sudo nano /etc/php/8.1/fpm/conf.d/99-custom-upload-limits.ini
   ```

2. **Agregar configuración:**
   ```ini
   upload_max_filesize = 500M
   post_max_size = 500M
   memory_limit = 512M
   max_execution_time = 1800
   max_input_time = 1800
   ```

3. **Guardar y salir** (Ctrl+X, luego Y, luego Enter)

4. **Reiniciar PHP-FPM:**
   ```bash
   sudo systemctl restart php8.1-fpm
   ```

5. **Verificar que se aplicó:**
   ```bash
   php -r "echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . PHP_EOL;"
   ```

## Verificar Versión de PHP

Para saber qué versión de PHP tienes instalada:
```bash
php -v
```

O para ver todas las versiones instaladas:
```bash
ls -la /etc/php/
```

## Verificar que PHP-FPM está usando la configuración

Después de hacer cambios, verifica que PHP-FPM los está usando. Crea un archivo PHP temporal:

```bash
echo "<?php phpinfo(); ?>" | sudo tee /var/www/tjsiddse/phpinfo-temp.php
```

Luego visita en el navegador:
```
https://plataforma.iddse.com.ar/phpinfo-temp.php
```

Busca la sección "PHP Core" y verifica los valores de:
- `upload_max_filesize`
- `post_max_size`
- `memory_limit`

**IMPORTANTE:** Después de verificar, elimina el archivo por seguridad:
```bash
sudo rm /var/www/tjsiddse/phpinfo-temp.php
```

## Solución de Problemas

### Los cambios no se aplican

1. **Verifica que reiniciaste PHP-FPM:**
   ```bash
   sudo systemctl restart php8.1-fpm
   ```

2. **Verifica que no hay errores en PHP-FPM:**
   ```bash
   sudo systemctl status php8.1-fpm
   ```

3. **Revisa los logs de PHP-FPM:**
   ```bash
   sudo tail -f /var/log/php8.1-fpm.log
   ```

4. **Verifica que el archivo de configuración tiene la sintaxis correcta:**
   ```bash
   php -m  # Debe ejecutarse sin errores
   ```

### Verificar qué archivo de configuración se está usando

```bash
php -i | grep "Loaded Configuration File"
```

**Nota:** Esto muestra el php.ini de CLI, no el de FPM. Para FPM, revisa los archivos en `/etc/php/[VERSION]/fpm/`.

## Script Automático

Si prefieres usar el script automático que creamos:

```bash
sudo bash /var/www/tjsiddse/configurar-limites-php-fpm.sh
```

Este script detecta automáticamente tu versión de PHP y configura todo por ti.
