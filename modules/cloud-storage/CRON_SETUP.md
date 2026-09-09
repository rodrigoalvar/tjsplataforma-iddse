# ⏰ Configuración de Cron para Worker R2

## 📋 Resumen

El worker R2 procesa automáticamente la cola de estudios pendientes de subir a Cloudflare R2. Este documento explica cómo configurar el cron para que se ejecute cada minuto.

---

## 🚀 Opción 1: Script Automático (Recomendado)

```bash
cd /var/www/tjsiddse/modules/cloud-storage
bash setup-cron.sh
```

El script:
- ✅ Verifica si ya existe una entrada de cron
- ✅ Agrega la entrada automáticamente
- ✅ Te muestra cómo verificar y ver los logs

---

## 🔧 Opción 2: Configuración Manual

### Paso 1: Editar crontab

```bash
crontab -e
```

### Paso 2: Agregar esta línea

```cron
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log 2>&1
```

**Explicación:**
- `*/1 * * * *` = cada minuto
- `php ...` = ejecuta el worker
- `>> ...` = redirige la salida al archivo de log
- `2>&1` = incluye errores en el log

### Paso 3: Guardar y salir

En `nano`: `Ctrl+X`, luego `Y`, luego `Enter`  
En `vi`: `:wq`

---

## ✅ Verificación

### Ver el cron configurado

```bash
crontab -l | grep r2-upload-worker
```

Deberías ver:
```
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log 2>&1
```

### Ver los logs en tiempo real

```bash
tail -f /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log
```

### Ejecutar el worker manualmente (para pruebas)

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php workers/r2-upload-worker.php
```

---

## 📊 Frecuencia Recomendada

| Frecuencia | Comando Cron | Cuándo usar |
|------------|--------------|-------------|
| Cada minuto | `*/1 * * * *` | **Recomendado** - Procesa estudios rápidamente |
| Cada 5 minutos | `*/5 * * * *` | Si hay pocos estudios o recursos limitados |
| Cada 15 minutos | `*/15 * * * *` | Solo para pruebas o desarrollo |

---

## 🐛 Troubleshooting

### El cron no se ejecuta

1. **Verificar que cron esté corriendo:**
   ```bash
   sudo systemctl status cron
   # o
   sudo service cron status
   ```

2. **Verificar permisos del archivo:**
   ```bash
   ls -la /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php
   ```
   Debe ser ejecutable por el usuario del cron.

3. **Verificar logs del sistema:**
   ```bash
   sudo tail -f /var/log/syslog | grep CRON
   ```

### El worker no procesa estudios

1. **Verificar que R2 esté habilitado:**
   ```bash
   grep R2_ENABLED /var/www/tjsiddse/modules/cloud-storage/.env
   ```
   Debe ser `R2_ENABLED=true`

2. **Verificar que haya estudios en la cola:**
   ```sql
   SELECT COUNT(*) FROM r2_queue WHERE status = 'pending';
   ```

3. **Verificar los logs del worker:**
   ```bash
   tail -50 /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log
   ```

### El log no se crea

1. **Verificar permisos del directorio:**
   ```bash
   ls -la /var/www/tjsiddse/modules/cloud-storage/logs/
   ```

2. **Crear el directorio si no existe:**
   ```bash
   mkdir -p /var/www/tjsiddse/modules/cloud-storage/logs
   chmod 755 /var/www/tjsiddse/modules/cloud-storage/logs
   ```

---

## 🔄 Desactivar el Cron

Si necesitas desactivar temporalmente el cron:

```bash
crontab -e
```

Comentar la línea agregando `#` al inicio:
```cron
# */1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log 2>&1
```

O eliminarla completamente.

---

## 📝 Notas

- El worker es **idempotente**: puede ejecutarse múltiples veces sin problemas
- Si no hay estudios pendientes, el worker termina rápidamente sin hacer nada
- Los errores se registran tanto en el log del worker como en `error_log` de PHP
- El worker procesa hasta `R2_UPLOAD_CONCURRENCY` estudios por ejecución (default: 2)

---

**Última actualización:** 2026-03-13
