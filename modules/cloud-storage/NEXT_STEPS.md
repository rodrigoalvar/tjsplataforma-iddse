# 🎯 Próximos Pasos - Cloud Storage R2

## ✅ Estado Actual

- ✅ **Estructura del módulo**: Completa
- ✅ **AWS SDK PHP**: Instalado
- ✅ **Archivo .env**: Existe en la carpeta del módulo
- ✅ **Tablas de BD**: Creadas
- ⚠️ **Configuración R2**: Pendiente (valores de ejemplo en .env)

---

## 📝 Pasos Inmediatos

### 1. Configurar Credenciales R2 en .env

Edita el archivo `.env` con tus credenciales reales de Cloudflare R2:

```bash
cd /var/www/tjsiddse/modules/cloud-storage
nano .env
```

**Cambiar estos valores:**
```env
R2_ENABLED=true                                    # ← Cambiar a true
R2_ACCOUNT_ID=tu_account_id_real                  # ← Tu Account ID real
R2_ACCESS_KEY=tu_access_key_real                  # ← Tu Access Key real
R2_SECRET_KEY=tu_secret_key_real                  # ← Tu Secret Key real
R2_BUCKET_NAME=tu_bucket_real                    # ← Tu bucket real
R2_CUSTOM_DOMAIN=https://r2.tanjousoft.com.ar    # ← Tu dominio R2 (si lo tienes)
```

**Dónde obtener las credenciales:**
1. Cloudflare Dashboard → R2 → Manage R2 API Tokens
2. Crear un nuevo API Token con permisos de lectura/escritura
3. Copiar Account ID, Access Key ID y Secret Access Key

---

### 2. Verificar Instalación

Accede a: `https://plataforma.iddse.com.ar/modules/cloud-storage/install.php`

Deberías ver:
- ✅ Todos los checks en verde
- ✅ R2 habilitado
- ✅ Credenciales configuradas

---

### 3. Probar el Módulo

#### 3.1. Encolar un estudio de prueba

```bash
# Obtener un Study ID de Orthanc
curl -u orthanc:orthanc http://localhost:8042/studies | jq '.[0]'

# Encolar (reemplazar con un Study ID real)
curl -X POST https://plataforma.iddse.com.ar/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "ABC123..."}'
```

**Respuesta esperada:**
```json
{
  "success": true,
  "queue_id": 1,
  "message": "Estudio encolado correctamente"
}
```

#### 3.2. Ejecutar worker

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

#### 3.3. Verificar en BD

```sql
-- Ver estado de la cola
SELECT * FROM r2_queue ORDER BY created_at DESC LIMIT 5;

-- Ver estudios subidos a R2
SELECT 
    orthanc_study_id,
    study_instance_uid,
    r2_status,
    total_instances,
    total_size_bytes,
    uploaded_at
FROM r2_studies 
WHERE r2_status = 'online';
```

#### 3.4. Obtener manifest con URLs presignadas

```bash
curl https://plataforma.iddse.com.ar/api/cloud-storage/manifest/TU_STUDY_ID
```

Deberías recibir un JSON con estructura:
```json
{
  "studyInstanceUID": "...",
  "patientName": "...",
  "series": [
    {
      "seriesInstanceUID": "...",
      "instances": [
        {
          "sopInstanceUID": "...",
          "url": "https://r2.tanjousoft.com.ar/...?X-Amz-Algorithm=..."
        }
      ]
    }
  ]
}
```

---

### 4. Configurar Cron (Recomendado)

Para procesar la cola automáticamente cada minuto:

```bash
crontab -e
```

Agregar:
```cron
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/log/r2-worker.log 2>&1
```

Verificar logs:
```bash
tail -f /var/log/r2-worker.log
```

---

## 🔍 Verificación Final

Ejecuta este checklist:

- [ ] `.env` configurado con credenciales R2 reales
- [ ] `R2_ENABLED=true` en .env
- [ ] `install.php` muestra todos los checks en verde
- [ ] Estudio encolado exitosamente
- [ ] Worker procesa sin errores
- [ ] Estructura creada en R2 (verificar en Cloudflare Dashboard)
- [ ] Manifest se genera correctamente
- [ ] URLs presignadas funcionan (probar descargar una imagen)
- [ ] Cron configurado (opcional)

---

## 🐛 Si Algo Falla

### Error: "R2_ACCOUNT_ID no está configurado"
- Verificar que `.env` tenga `R2_ENABLED=true`
- Verificar que las credenciales no sean valores de ejemplo

### Error: "AWS SDK PHP no está instalado"
```bash
cd /var/www/tjsiddse/modules/cloud-storage
composer require aws/aws-sdk-php
```

### Error en worker: "Error descargando instancia desde Orthanc"
- Verificar que Orthanc esté accesible
- Verificar credenciales en `api/config/orthanc_config.php`
- Verificar que el estudio exista en Orthanc

### Error: "Bucket no encontrado"
- Verificar que el bucket existe en Cloudflare R2
- Verificar que `R2_BUCKET_NAME` sea correcto
- Verificar permisos del API Token

---

## 📚 Documentación Adicional

- `README.md` - Documentación general
- `TESTING.md` - Guía de pruebas detallada
- `INSTALLATION_STEPS.md` - Pasos de instalación completos

---

**¡Listo para comenzar!** 🚀

Configura el `.env` con tus credenciales reales y sigue los pasos de prueba.
