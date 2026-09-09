# Changelog: Eliminación de Worker de Cloudflare y Mejoras

## Cambios Realizados

### 1. Worker de Cloudflare - DEPRECADO
- ✅ Archivos del Worker movidos a `archived/cloudflare-worker/`
- ✅ Método `triggerR2ExtractionWorker()` eliminado de `R2QueueProcessor.php`
- ✅ Endpoint `r2-extraction-callback.php` movido a `archived/`
- ✅ Referencias al Worker eliminadas del código activo
- ✅ Documentación creada en `archived/cloudflare-worker/README_DEPRECATED.md`

**Razón**: Limitaciones de tiempo de procesamiento en Cloudflare Workers (30s-15min) no son suficientes para ZIPs grandes de estudios DICOM.

### 2. Método ZIP Upload
- ✅ Estado final cambiado de `pending_extraction` a `done` cuando se sube ZIP
- ⚠️ El ZIP queda en R2 sin extraer (puede extraerse manualmente si es necesario)

### 3. Configuración
- ⚠️ `r2_worker_url` y `r2_worker_auth_token` aún existen en la configuración pero no se usan
- 💡 Pueden eliminarse del `.env` si no se planea usar el Worker en el futuro

## Próximos Pasos: Mejoras al Upload por Instancias

Ver `IMPROVEMENTS_INSTANCE_UPLOAD.md` para el plan completo de mejoras.

**Prioridad**: Implementar upload paralelo/concurrente para mejorar velocidad de upload.
