# 📋 Pasos de Instalación - Cloud Storage R2

## ✅ Estado Actual

Según el diagnóstico:
- ✅ PHP 8.1.2 funcionando
- ✅ Todas las rutas correctas
- ✅ Archivos legibles
- ✅ Clases cargadas correctamente
- ✅ `.env` existe en la carpeta del módulo
- ✅ `install.php` ejecuta sin errores fatales

---

## 🔧 Pasos Siguientes

### 1. Verificar y Configurar .env

Asegúrate de que tu `.env` tenga las credenciales correctas:

```bash
cd /var/www/tjsiddse/modules/cloud-storage
nano .env
```

**Configuración mínima requerida:**
```env
R2_ENABLED=true
R2_ACCOUNT_ID=tu_account_id_real
R2_ACCESS_KEY=tu_access_key_real
R2_SECRET_KEY=tu_secret_key_real
R2_BUCKET_NAME=tu_bucket_name
```

### 2. Instalar AWS SDK PHP

```bash
cd /var/www/tjsiddse/modules/cloud-storage
composer require aws/aws-sdk-php
```

O si ya tienes composer global:
```bash
composer install
```

### 3. Crear Tablas en Base de Datos

**Opción A: Usar install.php (recomendado)**
- Acceder a: `https://plataforma.iddse.com.ar/modules/cloud-storage/install.php`
- El script creará las tablas automáticamente

**Opción B: Manual**
```bash
mysql -u iddse -piddse263 tjsmedical_iddse < modules/cloud-storage/database/install.sql
```

**Verificar tablas creadas:**
```sql
SHOW TABLES LIKE 'r2_%';
SHOW TABLES LIKE 'cloud_storage%';
```

Deberías ver:
- `r2_queue`
- `r2_studies`
- `cloud_storage_config`

### 4. Verificar Instalación Completa

Acceder nuevamente a `install.php` y verificar:
- ✅ AWS SDK instalado
- ✅ Tablas creadas
- ✅ Configuración R2 válida

### 5. Probar el Módulo

#### 5.1. Encolar un estudio de prueba

```bash
# Obtener un Study ID de Orthanc
curl -u orthanc:orthanc http://localhost:8042/studies | jq '.[0]'

# Encolar (reemplazar TU_STUDY_ID)
curl -X POST https://plataforma.iddse.com.ar/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "TU_STUDY_ID"}'
```

#### 5.2. Ejecutar worker manualmente

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php workers/r2-upload-worker.php
```

**Salida esperada:**
```
[2026-03-06 XX:XX:XX] Iniciando worker R2...
Procesando cola...
Procesados: 1 de 1
[2026-03-06 XX:XX:XX] Worker finalizado.
```

#### 5.3. Verificar en base de datos

```sql
-- Ver estado de la cola
SELECT * FROM r2_queue ORDER BY created_at DESC LIMIT 5;

-- Ver estudios en R2
SELECT * FROM r2_studies WHERE r2_status = 'online';
```

#### 5.4. Obtener manifest

```bash
curl https://plataforma.iddse.com.ar/api/cloud-storage/manifest/TU_STUDY_ID
```

Deberías recibir un JSON con las instancias y URLs presignadas.

### 6. Configurar Cron (Opcional pero Recomendado)

Para procesar la cola automáticamente:

```bash
# Editar crontab
crontab -e

# Agregar (procesar cada minuto)
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/log/r2-worker.log 2>&1
```

---

## 🐛 Troubleshooting

### Error: "AWS SDK PHP no está instalado"
```bash
cd /var/www/tjsiddse/modules/cloud-storage
composer require aws/aws-sdk-php
```

### Error: "R2_ACCOUNT_ID no está configurado"
- Verificar que `.env` existe en `modules/cloud-storage/.env`
- Verificar que `R2_ENABLED=true`
- Verificar que las credenciales estén correctas

### Error: "No se pudo conectar a la base de datos"
- Verificar `config/database.php`
- Verificar credenciales de MySQL

### Error en worker: "Error descargando instancia desde Orthanc"
- Verificar que Orthanc esté accesible
- Verificar credenciales en `api/config/orthanc_config.php`
- Verificar que el estudio exista en Orthanc

### Tablas no se crean
- Verificar permisos de MySQL
- Ejecutar SQL manualmente: `mysql < database/install.sql`
- Verificar logs de PHP

---

## ✅ Checklist Final

- [ ] `.env` configurado con credenciales R2 válidas
- [ ] AWS SDK PHP instalado (`composer require aws/aws-sdk-php`)
- [ ] Tablas creadas en BD (`r2_queue`, `r2_studies`, `cloud_storage_config`)
- [ ] `install.php` muestra todos los checks en verde
- [ ] Estudio encolado exitosamente
- [ ] Worker procesa sin errores
- [ ] Manifest se genera correctamente
- [ ] URLs presignadas funcionan
- [ ] Cron configurado (opcional)

---

## 🎯 Próximos Pasos Después de Instalación

1. **Integrar con visores**: Modificar endpoints de estudios para incluir `r2_status`
2. **Panel de administración**: Crear `admin.php` para gestionar la cola
3. **Monitoreo**: Configurar alertas para errores en la cola
4. **Optimización**: Ajustar `R2_UPLOAD_CONCURRENCY` según rendimiento

---

**¿Listo para comenzar?** Sigue los pasos en orden y verifica cada uno antes de continuar.
