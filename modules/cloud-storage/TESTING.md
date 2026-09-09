# 🧪 Guía de Pruebas - Cloud Storage R2

## 📋 Checklist de Preparación

Antes de probar, asegúrate de:

- [ ] Instalar dependencias: `composer require aws/aws-sdk-php` (en el directorio del módulo)
- [ ] Ejecutar `install.php` para crear tablas
- [ ] Configurar `.env` con credenciales R2 válidas
- [ ] Tener al menos un estudio en Orthanc para probar

---

## 🧪 Prueba 1: Encolar Estudio Manualmente

### Paso 1: Obtener un Study ID de Orthanc

```bash
# Listar estudios disponibles
curl -u orthanc:orthanc http://localhost:8042/studies | jq '.[0]'
```

Copia el ID del estudio (ej: `abc123def456...`)

### Paso 2: Encolar el estudio

```bash
curl -X POST http://localhost/api/cloud-storage/enqueue \
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

### Paso 3: Verificar en BD

```sql
SELECT * FROM r2_queue WHERE orthanc_study_id = 'TU_STUDY_ID';
```

Deberías ver un registro con `status = 'pending'`.

---

## 🧪 Prueba 2: Ejecutar Worker Manualmente

### Ejecutar worker

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php workers/r2-upload-worker.php
```

**Salida esperada:**
```
[2026-03-06 20:00:00] Iniciando worker R2...
Procesando cola...
Procesados: 1 de 1
[2026-03-06 20:00:45] Worker finalizado.
```

### Verificar en BD

```sql
-- Verificar cola
SELECT * FROM r2_queue WHERE orthanc_study_id = 'TU_STUDY_ID';
-- Debería estar en status = 'done'

-- Verificar estudio en R2
SELECT * FROM r2_studies WHERE orthanc_study_id = 'TU_STUDY_ID';
-- Debería tener r2_status = 'online' y r2_manifest_path
```

---

## 🧪 Prueba 3: Verificar Estructura en R2

### Usar AWS CLI o Cloudflare Dashboard

Verificar que existan:

```
studies/
  └── {StudyInstanceUID}/
      ├── manifest.json
      └── series/
          └── {SeriesInstanceUID}/
              └── {SOPInstanceUID}.dcm
```

---

## 🧪 Prueba 4: Obtener Manifest con URLs Presignadas

### Obtener manifest

```bash
curl http://localhost/api/cloud-storage/manifest/TU_STUDY_ID
```

**Respuesta esperada:**
```json
{
  "studyInstanceUID": "1.2.3.4.5...",
  "patientName": "PACIENTE^TEST",
  "patientId": "12345",
  "studyDescription": "CT ABDOMEN",
  "studyDate": "20260101",
  "series": [
    {
      "seriesInstanceUID": "1.2.3.4.6...",
      "seriesNumber": 1,
      "seriesDescription": "SERIES 1",
      "modality": "CT",
      "instances": [
        {
          "sopInstanceUID": "1.2.3.4.7...",
          "instanceNumber": 1,
          "fileSize": 7962678,
          "url": "https://r2.tanjousoft.com.ar/studies/...?X-Amz-Algorithm=..."
        }
      ]
    }
  ]
}
```

**Verificar:**
- ✅ Cada `instances[].url` es una URL presignada
- ✅ Las URLs apuntan al dominio R2 configurado
- ✅ No hay campo `path` (solo `url`)

---

## 🧪 Prueba 5: Verificar Estado

```bash
curl http://localhost/api/cloud-storage/status/TU_STUDY_ID
```

**Respuesta esperada:**
```json
{
  "success": true,
  "data": {
    "orthanc_study_id": "TU_STUDY_ID",
    "queue_status": "done",
    "r2_status": "online",
    "r2_manifest_path": "studies/1.2.3.4.5.../manifest.json",
    "total_instances": 50,
    "total_size_bytes": 398133900,
    "uploaded_at": "2026-03-06 20:00:45"
  }
}
```

---

## 🐛 Troubleshooting

### Error: "AWS SDK PHP no está instalado"

```bash
cd /var/www/tjsiddse/modules/cloud-storage
composer require aws/aws-sdk-php
```

### Error: "R2_ACCOUNT_ID no está configurado"

Verificar `.env` en la carpeta del módulo (`modules/cloud-storage/.env`):
```bash
cd /var/www/tjsiddse/modules/cloud-storage
cp .env.example .env
# Editar .env con tus credenciales
```

```env
R2_ENABLED=true
R2_ACCOUNT_ID=tu_account_id
R2_ACCESS_KEY=tu_access_key
R2_SECRET_KEY=tu_secret_key
```

### Error: "No se pudo conectar a la base de datos"

Verificar `config/database.php` y credenciales.

### Error en worker: "Error descargando instancia desde Orthanc"

- Verificar que Orthanc esté accesible
- Verificar credenciales en `orthanc_config.php`
- Verificar que el estudio exista en Orthanc

### URLs presignadas no funcionan

- Verificar que `R2_CUSTOM_DOMAIN` esté configurado correctamente
- Verificar que el dominio apunte al bucket R2
- Verificar permisos del bucket (debe ser privado)

---

## 📊 Logs

### Ver logs del worker

```bash
tail -f /var/log/r2-worker.log
```

### Ver logs de PHP

```bash
tail -f /var/log/php-fpm/error.log
# o
tail -f /var/log/apache2/error.log
```

---

## ✅ Checklist Final

- [ ] Estudio encolado correctamente
- [ ] Worker procesa sin errores
- [ ] Estructura creada en R2
- [ ] Manifest.json generado correctamente
- [ ] URLs presignadas funcionan
- [ ] Estado del estudio correcto en BD

---

**¡Listo para integrar con visores!** 🎉
