# ✅ Probar sin Custom Domain

## ¿Funciona sin Custom Domain?

**Sí, funciona perfectamente.** El módulo está diseñado para trabajar con o sin custom domain.

### Sin Custom Domain
- ✅ Las URLs presignadas usarán el endpoint interno de R2
- ✅ Formato: `https://{account_id}.r2.cloudflarestorage.com/{bucket}/{path}?signature=...`
- ✅ Funciona exactamente igual, solo cambia la URL base

### Con Custom Domain (opcional, para después)
- ✅ URLs más limpias: `https://r2.tanjousoft.com.ar/{path}?signature=...`
- ✅ Mejor para branding
- ✅ Requiere configuración adicional en Cloudflare

---

## Configuración Actual

Tu `.env` tiene:
```env
R2_CUSTOM_DOMAIN=https://r2.tanjousoft.com.ar
```

**Opciones:**

### Opción 1: Comentar la línea (recomendado para pruebas)
```env
# R2_CUSTOM_DOMAIN=https://r2.tanjousoft.com.ar
```

### Opción 2: Dejarla vacía
```env
R2_CUSTOM_DOMAIN=
```

### Opción 3: Dejarla como está
El código detectará si el custom domain está realmente configurado y funcionará correctamente.

---

## Probar Ahora

### 1. Verificar configuración

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php -r "require_once 'config/cloud_storage_config.php'; \$c = CloudStorageConfig::load(); echo 'R2 Enabled: ' . (\$c['r2_enabled'] ? 'YES' : 'NO') . PHP_EOL; echo 'Bucket: ' . \$c['r2_bucket_name'] . PHP_EOL; echo 'Custom Domain: ' . (empty(\$c['r2_custom_domain']) ? 'NO (usará endpoint interno)' : \$c['r2_custom_domain']) . PHP_EOL;"
```

### 2. Probar conexión R2

```bash
php -r "
require_once 'config/cloud_storage_config.php';
require_once 'drivers/R2StorageDriver.php';
\$config = CloudStorageConfig::load();
\$driver = new R2StorageDriver(\$config);
echo 'Testing R2 connection...' . PHP_EOL;
if (\$driver->testConnection()) {
    echo '✓ Conexión exitosa!' . PHP_EOL;
} else {
    echo '✗ Error de conexión' . PHP_EOL;
}
"
```

### 3. Encolar y procesar un estudio

```bash
# Encolar
curl -X POST https://plataforma.iddse.com.ar/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "TU_STUDY_ID"}'

# Procesar
php workers/r2-upload-worker.php

# Ver manifest (las URLs usarán endpoint interno de R2)
curl https://plataforma.iddse.com.ar/api/cloud-storage/manifest/TU_STUDY_ID
```

---

## URLs Generadas

### Sin Custom Domain
```
https://t370ESQ1mCxDX9BzsmagOyr4YnUe0i6YyYZzgzAx.r2.cloudflarestorage.com/tjsmedical/tjsiddse/{StudyInstanceUID}/series/{SeriesInstanceUID}/{SOPInstanceUID}.dcm?X-Amz-Algorithm=...
```

### Con Custom Domain (cuando lo configures)
```
https://r2.tanjousoft.com.ar/tjsiddse/{StudyInstanceUID}/series/{SeriesInstanceUID}/{SOPInstanceUID}.dcm?X-Amz-Algorithm=...
```

**Ambas funcionan igual**, solo cambia la URL base.

---

## Configurar Custom Domain Después

Cuando quieras configurar el custom domain:

1. **En Cloudflare Dashboard:**
   - R2 → Tu bucket → Settings → Custom Domain
   - Agregar dominio: `r2.tanjousoft.com.ar`
   - Configurar DNS según instrucciones

2. **En tu .env:**
   - Descomentar o actualizar `R2_CUSTOM_DOMAIN`

3. **Listo** - Las nuevas URLs presignadas usarán el custom domain

---

## ✅ Conclusión

**Puedes probar y usar el módulo perfectamente sin custom domain.** 

El custom domain es solo una mejora estética/UX, no es requerido para el funcionamiento.
