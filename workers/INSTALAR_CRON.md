# Instrucciones para Instalar el Cron Job del Worker de Transcripción

## Ubicación del Cron Job

**IMPORTANTE:** El cron job debe configurarse en el **mismo servidor donde está el sistema** (192.168.0.250, servidor nginx), porque:

1. El worker PHP necesita acceso a la base de datos local
2. El worker necesita acceso a los archivos de audio en el servidor
3. El worker necesita ejecutar el código PHP del sistema

## Pasos para Instalar

### Opción 1: Usando el script automático (Recomendado)

```bash
# Conectarse al servidor nginx (192.168.0.250)
ssh usuario@192.168.0.250

# Ejecutar el script de instalación
/var/www/tjsiddse/workers/install-cron.sh
```

### Opción 2: Instalación manual

1. **Conectarse al servidor nginx (192.168.0.250):**
   ```bash
   ssh usuario@192.168.0.250
   ```

2. **Editar el crontab del usuario que ejecuta el sistema (normalmente el usuario del servidor web):**
   ```bash
   crontab -e
   ```
   
   Si el sistema se ejecuta con un usuario específico (ej: `www-data`, `nginx`, `apache`), puedes necesitar:
   ```bash
   sudo crontab -u www-data -e
   # o
   sudo crontab -u nginx -e
   ```

3. **Agregar esta línea al final del archivo:**
   ```bash
   * * * * * /usr/bin/php /var/www/tjsiddse/workers/transcription-queue-worker.php >> /dev/null 2>&1
   ```
   
   **Nota:** Usa la ruta completa de PHP. Para encontrarla:
   ```bash
   which php
   ```

4. **Guardar y salir** (en vi/nano: `Esc` luego `:wq` o `Ctrl+X` luego `Y`)

5. **Verificar que se instaló correctamente:**
   ```bash
   crontab -l
   # o si usaste sudo:
   sudo crontab -u www-data -l
   ```

## Verificar que Funciona

1. **Ver los logs del worker:**
   ```bash
   tail -f /var/www/tjsiddse/logs/transcription-queue-worker.log
   ```

2. **Probar manualmente el worker:**
   ```bash
   php /var/www/tjsiddse/workers/transcription-queue-worker.php
   ```

3. **Verificar en la interfaz web:**
   - Ir a `configuracion.html` → pestaña "AI Informes"
   - Activar "Transcripción Automática"
   - Debería mostrar "Cron job configurado correctamente"

## Frecuencia del Cron

El cron está configurado para ejecutarse **cada minuto** (`* * * * *`). Esto significa que:
- El worker se ejecuta cada 60 segundos
- Procesa un audio a la vez (configurable en `max_concurrent_transcriptions`)
- Si no hay audios pendientes, el worker termina inmediatamente

## Solución de Problemas

### El cron no se ejecuta
- Verificar que el usuario tiene permisos para ejecutar PHP
- Verificar que la ruta de PHP es correcta: `which php`
- Verificar los logs del sistema: `grep CRON /var/log/syslog`

### El worker no encuentra los archivos
- Verificar permisos: `ls -la /var/www/tjsiddse/workers/transcription-queue-worker.php`
- Verificar que el usuario del cron tiene acceso a los archivos

### Error de conexión a la base de datos
- Verificar que la configuración en `config/database.php` es correcta
- Verificar que el usuario de la base de datos tiene permisos

## Desinstalar el Cron Job

```bash
crontab -e
# Eliminar la línea del worker
# Guardar y salir
```
