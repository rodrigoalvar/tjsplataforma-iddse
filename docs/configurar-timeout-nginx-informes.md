# Configurar Timeout de Nginx para Generación de Informes AI

El error 504 (Gateway Timeout) ocurre cuando Nginx corta la conexión antes de que Ollama termine de generar el informe. Los modelos grandes como `alibayram/medgemma:27b` pueden tardar 10-20 minutos en generar informes largos.

## Solución Rápida (Script Automático)

Si tienes acceso sudo, puedes usar el script automático:

```bash
sudo bash /var/www/tjsiddse/configurar-timeout-nginx.sh
```

Este script:
- Detecta automáticamente tu configuración de Nginx
- Crea un backup antes de modificar
- Configura los timeouts necesarios
- Verifica la configuración
- Recarga Nginx si todo está correcto

## Configuración de Nginx

Agrega o modifica las siguientes directivas en tu configuración de Nginx:

```nginx
server {
    # ... otras configuraciones ...
    
    # Timeout para peticiones largas (generación de informes AI)
    # Modelos grandes como medgemma:27b pueden tardar 10-20 minutos
    proxy_read_timeout 1800s;      # 30 minutos
    proxy_connect_timeout 60s;     # 1 minuto para conexión
    proxy_send_timeout 1800s;      # 30 minutos
    
    # Para FastCGI (si usas PHP-FPM)
    fastcgi_read_timeout 1800s;    # 30 minutos
    fastcgi_send_timeout 1800s;    # 30 minutos
    
    # ... resto de configuraciones ...
}
```

## Ubicación del archivo

Generalmente en:
- `/etc/nginx/sites-available/tu-sitio.conf`
- O en el bloque `location` específico para `/api/ai-informes.php`

## Ejemplo completo

```nginx
location /api/ai-informes.php {
    proxy_read_timeout 1800s;      # 30 minutos
    proxy_connect_timeout 60s;     # 1 minuto para conexión
    proxy_send_timeout 1800s;      # 30 minutos
    
    # Si usas PHP-FPM
    fastcgi_read_timeout 1800s;    # 30 minutos
    fastcgi_send_timeout 1800s;    # 30 minutos
    
    # ... resto de configuración PHP ...
}
```

## Después de modificar

```bash
# Verificar configuración
sudo nginx -t

# Recargar Nginx
sudo systemctl reload nginx
```

## Verificación

Los timeouts ahora deberían ser:
- **PHP**: 1800 segundos (30 minutos) - configurar en php.ini o PHP-FPM
- **Ollama Client**: 1800 segundos (30 minutos) - configurable en Configuración → AI Informes
- **Nginx**: 1800 segundos (30 minutos) - requiere configuración manual (ver arriba)

## Nota

Si el modelo sigue tardando más de 30 minutos, considera:
1. Usar un modelo más pequeño (ej: `alibayram/medgemma:4b` en lugar de `:27b`)
2. Reducir la longitud de la transcripción
3. Verificar que Ollama tenga suficientes recursos (RAM, GPU)
4. Aumentar aún más los timeouts si es necesario (pero esto puede indicar un problema de rendimiento)

## Verificación

Después de configurar, verifica que los timeouts se aplicaron:

```bash
# Ver configuración de Nginx
sudo nginx -T | grep -E "read_timeout|send_timeout"
```

Deberías ver valores de 1800s (30 minutos) para los timeouts relacionados con FastCGI.
