# Configuración del Worker de Cloudflare para Extracción de ZIPs

## Resumen

Se ha implementado un Worker de Cloudflare que se ejecuta automáticamente cuando se sube un ZIP a R2, lo descomprime directamente en el bucket y notifica al backend PHP cuando termina.

## Archivos Creados

### Worker de Cloudflare
- `cloudflare-worker/wrangler.toml` - Configuración del Worker
- `cloudflare-worker/package.json` - Dependencias del proyecto
- `cloudflare-worker/src/index.js` - Código del Worker
- `cloudflare-worker/README.md` - Documentación del Worker
- `cloudflare-worker/INSTALL.md` - Guía de instalación detallada

### Backend PHP
- `api/r2-extraction-callback.php` - Endpoint que recibe notificaciones del Worker
- Actualizado `R2QueueProcessor.php` - Agregado método `triggerR2ExtractionWorker()`
- Actualizado `config/cloud_storage_config.php` - Agregadas configuraciones `r2_worker_url` y `r2_worker_auth_token`

## Flujo Completo

1. **PHP sube ZIP a R2** → Estado: `guardado_en_r2`
2. **PHP dispara Worker** → Llama a `triggerR2ExtractionWorker()`
3. **Worker responde 202** → Estado cambia a: `extraccion_en_curso`
4. **Worker descomprime ZIP** → Dentro de R2, sin descargar
5. **Worker elimina ZIP** → Después de extraer exitosamente
6. **Worker llama callback PHP** → `r2-extraction-callback.php`
7. **PHP actualiza estado** → Estado final: `estudio_online`

## Configuración Requerida

### 1. Instalar y Desplegar Worker

```bash
cd modules/cloud-storage/cloudflare-worker
npm install -g wrangler
wrangler login
wrangler secret put AUTH_TOKEN
# Ingresar un token secreto fuerte (ej: openssl rand -hex 32)
npm install
wrangler deploy
```

Después del deploy, copiar la URL del Worker (ej: `https://r2-zip-extractor.tu-account.workers.dev`)

### 2. Configurar en PHP

Agregar al `.env` del módulo (`modules/cloud-storage/.env`):

```env
R2_WORKER_URL=https://r2-zip-extractor.tu-account.workers.dev
R2_WORKER_AUTH_TOKEN=el-mismo-token-que-configuraste-en-wrangler-secret
```

### 3. Verificar Configuración

El Worker debe estar configurado con:
- Binding al bucket R2: `tjsmedical` (en `wrangler.toml`)
- Token de autenticación: Mismo token en Worker y PHP

## Estados en la Cola

- `guardado_en_r2` - ZIP subido, esperando extracción
- `extraccion_en_curso` - Worker procesando extracción
- `estudio_online` - Extracción completada, estudio disponible en R2
- `error` - Error durante extracción

## Pruebas

### Probar Worker Manualmente

```bash
curl -X POST https://r2-zip-extractor.tu-account.workers.dev \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tu-token" \
  -d '{
    "bucket": "tjsmedical",
    "zipKey": "studies/1.2.3.4.5.6/study.zip",
    "studyInstanceUID": "1.2.3.4.5.6",
    "queueId": 123,
    "callbackUrl": "https://plataforma.iddse.com.ar/modules/cloud-storage/api/r2-extraction-callback.php"
  }'
```

### Ver Logs del Worker

```bash
wrangler tail
```

### Ver Logs del Backend

```bash
tail -f modules/cloud-storage/logs/r2-worker.log
```

## Notas Importantes

1. **Límites de Workers**: 
   - Plan gratuito: 30 segundos máximo
   - Plan pago: hasta 15 minutos
   - Para ZIPs muy grandes, considerar Durable Objects

2. **Librería ZIP**: 
   - Actualmente usa `fflate` desde CDN
   - Para producción, instalar localmente: `npm install fflate`
   - Luego cambiar import en `src/index.js`

3. **Seguridad**:
   - El token debe ser fuerte y secreto
   - Nunca commitees tokens en el código
   - Usa `wrangler secret put` para configurarlo

4. **Callback URL**:
   - Debe ser accesible desde internet
   - Debe tener HTTPS (Cloudflare Workers requiere HTTPS)
   - El endpoint valida el token antes de procesar

## Troubleshooting

### Worker no se ejecuta
- Verificar que `R2_WORKER_URL` y `R2_WORKER_AUTH_TOKEN` estén configurados en `.env`
- Verificar logs de PHP: `tail -f modules/cloud-storage/logs/r2-worker.log`

### Error 401 Unauthorized
- Verificar que el token en `.env` coincida con el configurado en `wrangler secret put AUTH_TOKEN`

### Error en callback
- Verificar que `r2-extraction-callback.php` sea accesible desde internet
- Verificar logs del servidor PHP
- Verificar que el token en el callback coincida con el esperado

### Worker timeout
- ZIP muy grande (>500MB) puede exceder límite de tiempo
- Considerar usar Durable Objects o procesar por partes
