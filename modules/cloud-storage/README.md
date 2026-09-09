# 📦 Módulo Cloud Storage - Cloudflare R2

**Sistema TJSMEDICAL - Portal de Estudios Médicos**  
**Versión**: 1.0.0  
**Fecha**: 2026-03-06

---

## 📋 Descripción

Módulo de almacenamiento en la nube para estudios DICOM usando Cloudflare R2. Permite:

- ✅ Mantener Orthanc local como fuente de verdad
- ✅ Copiar estudios seleccionados a R2 en background (sin bloquear Orthanc)
- ✅ Generar manifest JSON por estudio en R2 con lista de imágenes
- ✅ Cargar estudios desde R2 mediante URLs wadouri (máximo rendimiento WAN)
- ✅ R2 privado + URLs firmadas (sin acceso público)

---

## 🚀 Instalación Rápida

### 1. Instalar dependencias

```bash
cd /var/www/tjsiddse/modules/cloud-storage
composer require aws/aws-sdk-php
```

### 2. Crear tablas en BD

```bash
mysql -u root -p tu_base_de_datos < database/install.sql
```

### 3. Configurar .env

Crear el archivo `.env` en la carpeta del módulo:

```bash
cd /var/www/tjsiddse/modules/cloud-storage
cp .env.example .env
```

Editar `.env` con tus credenciales:

```env
R2_ENABLED=true
R2_ACCOUNT_ID=tu_account_id
R2_ACCESS_KEY=tu_access_key
R2_SECRET_KEY=tu_secret_key
R2_BUCKET_NAME=dicom-studies
R2_REGION=auto
R2_CUSTOM_DOMAIN=https://r2.tanjousoft.com.ar
R2_STORAGE_PREFIX=studies/
R2_UPLOAD_CONCURRENCY=2
R2_PRESIGNED_TTL=600
```

**Nota:** El módulo busca el `.env` primero en su propia carpeta, y si no existe, busca en la raíz del proyecto como fallback.

### 4. Configurar cron para worker (opcional)

```bash
# Procesar cola cada minuto
*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/log/r2-worker.log 2>&1
```

---

## 📁 Estructura del Módulo

```
modules/cloud-storage/
├── CloudStorageManager.php      # Gestor principal
├── ManifestBuilder.php          # Constructor de manifest.json
├── R2QueueProcessor.php         # Procesador de cola
│
├── drivers/
│   ├── StorageDriverInterface.php
│   ├── BaseStorageDriver.php
│   └── R2StorageDriver.php      # Driver específico R2
│
├── api/
│   ├── enqueue.php              # POST /api/cloud-storage/enqueue
│   ├── manifest.php             # GET /api/cloud-storage/manifest/{id}
│   └── status.php               # GET /api/cloud-storage/status/{id}
│
├── config/
│   └── cloud_storage_config.php  # Configuración
│
├── database/
│   ├── install.sql               # Script de instalación
│   └── add_r2_resync_safe.sql   # Re-sync incremental: is_resync (cola) + r2_sync_status (r2_studies)
│
├── workers/
│   └── r2-upload-worker.php     # Worker para procesar cola
│
└── lua/
    └── notify-r2.lua             # Script Lua para Orthanc (opcional)
```

---

## 🔧 Uso

### Encolar un estudio

```bash
curl -X POST http://localhost/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "abc123..."}'
```

### Obtener manifest con URLs presignadas

```bash
curl http://localhost/api/cloud-storage/manifest/abc123...
```

### Verificar estado

```bash
curl http://localhost/api/cloud-storage/status/abc123...
```

---

## 📝 Notas

- **Re-sync R2**: si en Orthanc hay más instancias que en `r2_studies`, un nuevo encolado crea un trabajo `is_resync` que sube solo objetos faltantes (HeadObject) y vuelve a publicar el manifest. Ejecutar `database/add_r2_resync_safe.sql` si la BD existente no tiene las columnas.
- El módulo es **no intrusivo**: Orthanc sigue siendo la fuente de verdad
- Las subidas se hacen en **background** sin bloquear Orthanc
- El bucket R2 es **privado**; solo URLs presignadas temporales
- Compatible con visores que soporten **wadouri:**

---

## 🔗 Documentación

Ver `docs/` para documentación detallada (pendiente de crear).
