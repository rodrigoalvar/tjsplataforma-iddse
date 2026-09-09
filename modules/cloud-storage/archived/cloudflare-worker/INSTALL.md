# Instalación del Worker de Cloudflare para Extracción de ZIPs

## Requisitos Previos

1. **Node.js** (v16 o superior)
2. **Cuenta de Cloudflare** con Workers habilitado
3. **Bucket R2** configurado (`tjsmedical`)

## Pasos de Instalación

### 1. Instalar Wrangler CLI

```bash
npm install -g wrangler
# O localmente en el proyecto
npm install wrangler --save-dev
```

### 2. Autenticarse con Cloudflare

```bash
wrangler login
```

Esto abrirá tu navegador para autenticarte con Cloudflare.

### 3. Configurar el Bucket R2

Asegúrate de que el bucket `tjsmedical` existe en tu cuenta de Cloudflare R2.

### 4. Configurar Secretos del Worker

```bash
cd modules/cloud-storage/cloudflare-worker

# Configurar token de autenticación (debe ser el mismo que en PHP)
wrangler secret put AUTH_TOKEN
# Ingresar el token secreto (ej: genera uno con: openssl rand -hex 32)

# Opcional: Configurar URL de callback (si quieres sobrescribir la del código)
# wrangler secret put CALLBACK_URL
```

### 5. Desplegar el Worker

```bash
npm install
wrangler deploy
```

Después del deploy, Wrangler mostrará la URL del Worker, por ejemplo:
```
https://r2-zip-extractor.tu-account.workers.dev
```

### 6. Configurar en PHP

Agregar al `.env` del módulo (`modules/cloud-storage/.env`):

```env
R2_WORKER_URL=https://r2-zip-extractor.tu-account.workers.dev
R2_WORKER_AUTH_TOKEN=el-mismo-token-que-configuraste-en-wrangler-secret
```

### 7. Probar el Worker

Puedes probar el Worker manualmente con curl:

```bash
curl -X POST https://r2-zip-extractor.tu-account.workers.dev \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer tu-token-aqui" \
  -d '{
    "bucket": "tjsmedical",
    "zipKey": "studies/1.2.3.4.5.6/study.zip",
    "studyInstanceUID": "1.2.3.4.5.6",
    "queueId": 123,
    "callbackUrl": "https://plataforma.iddse.com.ar/modules/cloud-storage/api/r2-extraction-callback.php"
  }'
```

Deberías recibir un `202 Accepted` si todo está bien.

## Desarrollo Local

```bash
# Ejecutar Worker localmente
wrangler dev

# Ver logs en tiempo real
wrangler tail
```

## Notas Importantes

- **Límites de Workers**: 
  - Plan gratuito: 30 segundos de ejecución máxima
  - Plan pago: hasta 15 minutos
  - Para ZIPs muy grandes (>500MB), considerar usar Durable Objects

- **Librería ZIP**: 
  - Actualmente usa `fflate` desde CDN
  - Para producción, considera instalar `fflate` localmente: `npm install fflate`
  - Luego cambiar el import en `src/index.js` a: `import { unzipSync } from 'fflate';`

- **Seguridad**:
  - El token `AUTH_TOKEN` debe ser fuerte y secreto
  - Nunca commitees el token en el código
  - Usa `wrangler secret put` para configurarlo de forma segura

## Troubleshooting

### Error: "ZIP no encontrado en R2"
- Verifica que el `zipKey` sea correcto
- Verifica que el bucket esté correctamente configurado en `wrangler.toml`

### Error: "Invalid authentication token"
- Verifica que el token en `.env` de PHP coincida con el configurado en `wrangler secret put AUTH_TOKEN`

### Error: "Callback failed"
- Verifica que la URL del callback sea accesible desde internet
- Verifica que el endpoint `r2-extraction-callback.php` esté funcionando
- Revisa los logs del servidor PHP

### Worker timeout
- Si el ZIP es muy grande, el Worker puede exceder el tiempo límite
- Considera usar Durable Objects para procesamiento asíncrono
- O dividir el ZIP en partes más pequeñas
