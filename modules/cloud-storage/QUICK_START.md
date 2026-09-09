# 🚀 Inicio Rápido - Cloud Storage R2

## ✅ Instalación Completada

Tu módulo está instalado y configurado correctamente:
- ✅ AWS SDK PHP instalado
- ✅ Tablas de BD creadas
- ✅ R2 habilitado y configurado
- ✅ Bucket: `tjsmedical`

---

## 🧪 Prueba Rápida

### 1. Verificar que todo funciona

Accede a: `https://plataforma.iddse.com.ar/modules/cloud-storage/test-quick.php`

Este script verificará:
- Configuración R2
- Conexión a R2
- Base de datos
- Endpoints API

### 2. Encolar tu primer estudio

**Paso 1: Obtener un Study ID de Orthanc**

```bash
# Listar estudios disponibles
curl -u orthanc:orthanc http://localhost:8042/studies | jq '.[0]'
```

Copia el ID del estudio (ej: `abc123def456...`)

**Paso 2: Encolar el estudio**

```bash
curl -X POST https://plataforma.iddse.com.ar/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "TU_STUDY_ID_AQUI"}'
```

**Respuesta esperada:**
```json
{
  "success": true,
  "queue_id": 1,
  "message": "Estudio encolado correctamente"
}
```

### 3. Procesar la cola (subir a R2)

**Opción A: Ejecutar manualmente (para pruebas)**

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php workers/r2-upload-worker.php
```

**Salida esperada:**
```
[2026-03-12 XX:XX:XX] Iniciando worker R2...
Procesando cola...
Procesados: 1 de 1
[2026-03-12 XX:XX:XX] Worker finalizado.
```

**Opción B: Configurar cron (para producción)**

```bash
crontab -e
```

Agregar:
```cron
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/log/r2-worker.log 2>&1
```

### 4. Verificar que el estudio está en R2

**En base de datos:**
```sql
SELECT * FROM r2_studies WHERE r2_status = 'online';
```

**Obtener manifest con URLs presignadas:**
```bash
curl https://plataforma.iddse.com.ar/api/cloud-storage/manifest/TU_STUDY_ID
```

Deberías recibir un JSON con todas las instancias y sus URLs presignadas.

---

## 📊 Verificar Estado

### Ver estado de un estudio

```bash
curl https://plataforma.iddse.com.ar/api/cloud-storage/status/TU_STUDY_ID
```

### Ver cola de estudios

```sql
SELECT 
    id,
    orthanc_study_id,
    status,
    retry_count,
    last_error,
    created_at,
    updated_at
FROM r2_queue 
ORDER BY created_at DESC 
LIMIT 10;
```

### Ver estudios en R2

```sql
SELECT 
    orthanc_study_id,
    study_instance_uid,
    r2_status,
    total_instances,
    ROUND(total_size_bytes / 1024 / 1024, 2) as size_mb,
    uploaded_at
FROM r2_studies 
WHERE r2_status = 'online'
ORDER BY uploaded_at DESC;
```

---

## 🔧 Comandos Útiles

### Ver logs del worker (si usas cron)

```bash
tail -f /var/log/r2-worker.log
```

### Re-procesar un estudio que falló

```sql
-- Cambiar status a 'pending' para que se procese de nuevo
UPDATE r2_queue SET status = 'pending', retry_count = 0 WHERE id = X;
```

### Limpiar cola de errores

```sql
-- Eliminar estudios con error después de 3 intentos
DELETE FROM r2_queue WHERE status = 'error' AND retry_count >= 3;
```

---

## 🎯 Próximos Pasos

1. **Probar con un estudio real** - Encolar y procesar
2. **Verificar estructura en R2** - Revisar en Cloudflare Dashboard
3. **Configurar cron** - Para procesamiento automático
4. **Integrar con visores** - Modificar endpoints para usar R2 cuando esté disponible

---

## 📚 Documentación

- `README.md` - Documentación general
- `TESTING.md` - Guía de pruebas detallada
- `NEXT_STEPS.md` - Próximos pasos después de instalación
- `INSTALLATION_STEPS.md` - Pasos de instalación completos

---

**¡Listo para usar!** 🎉
