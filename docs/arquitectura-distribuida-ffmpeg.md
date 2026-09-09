# Arquitectura Distribuida: Conversión de Audio con ffmpeg

## Problema

Cuando tienes una arquitectura distribuida:
- **Servidor 1**: Nginx + PHP-FPM (aplicación web)
- **Servidor 2**: Whisper.cpp (transcripción)

El archivo M4A necesita convertirse a MP3 **antes** de enviarlo a Whisper, pero la conversión debe hacerse en el servidor donde está PHP-FPM, no en el servidor de Whisper.

## Solución

### Opción 1: Instalar ffmpeg en el servidor de Nginx/PHP-FPM (RECOMENDADO)

Instala ffmpeg en el servidor donde está corriendo PHP-FPM:

```bash
# En el servidor donde está Nginx/PHP-FPM
sudo apt-get update
sudo apt-get install ffmpeg
```

Luego configura el PATH en PHP-FPM:

```bash
sudo bash /var/www/tjsiddse/configurar-path-php-fpm.sh
```

### Opción 2: Usar ruta absoluta si ffmpeg está en ubicación no estándar

Si ffmpeg está instalado pero en una ubicación no estándar, puedes modificar `WhisperClient.php` para usar esa ruta específica.

### Opción 3: Enviar archivo sin conversión (si Whisper soporta M4A)

Actualmente Whisper.cpp no soporta M4A directamente, por lo que esta opción no es viable.

## Verificación

Después de instalar ffmpeg en el servidor de Nginx/PHP-FPM:

1. Verifica que ffmpeg esté instalado:
   ```bash
   which ffmpeg
   ffmpeg -version
   ```

2. Verifica desde PHP:
   ```bash
   php -r "echo shell_exec('ffmpeg -version 2>&1');"
   ```

3. Accede al script de verificación:
   ```
   https://plataforma.iddse.com.ar/verificar-ffmpeg.php
   ```

## Flujo de Conversión

```
Usuario sube M4A
    ↓
Servidor Nginx/PHP-FPM recibe archivo
    ↓
PHP convierte M4A → MP3 usando ffmpeg local
    ↓
PHP envía MP3 a Servidor Whisper (192.168.0.33:9090)
    ↓
Whisper transcribe MP3
    ↓
Resultado devuelto a PHP
    ↓
PHP muestra transcripción al usuario
```

## Nota Importante

**ffmpeg debe estar instalado en el servidor donde está PHP-FPM**, no en el servidor de Whisper, porque la conversión ocurre antes de enviar el archivo a Whisper.
