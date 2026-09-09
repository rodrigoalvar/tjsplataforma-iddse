# Cloudflare Worker para Extracción de ZIPs en R2

Este Worker se encarga de descomprimir ZIPs subidos a R2 directamente en el bucket, sin necesidad de descargarlos al servidor PHP.

## Instalación y Configuración

### 1. Instalar Wrangler CLI

```bash
npm install -g wrangler
# O localmente
npm install wrangler --save-dev
```

### 2. Autenticarse con Cloudflare

```bash
wrangler login
```

### 3. Configurar Secretos

```bash
# Configurar token de autenticación
wrangler secret put AUTH_TOKEN
# Ingresar el mismo token que configuraste en PHP (.env)

# Opcional: Configurar URL de callback
wrangler secret put CALLBACK_URL
```

### 4. Desplegar Worker

```bash
cd modules/cloud-storage/cloudflare-worker
npm install
wrangler deploy
```

### 5. Obtener URL del Worker

Después del deploy, Wrangler mostrará la URL del Worker, por ejemplo:
```
https://r2-zip-extractor.tu-account.workers.dev
```

Esta URL debe configurarse en el `.env` del módulo como `R2_WORKER_URL`.

## Configuración en PHP

Agregar al `.env` del módulo:

```env
R2_WORKER_URL=https://r2-zip-extractor.tu-account.workers.dev
R2_WORKER_AUTH_TOKEN=tu-token-secreto-aqui
```

## Flujo de Funcionamiento

1. PHP sube ZIP a R2 y marca estado como `guardado_en_r2`
2. PHP llama al Worker con POST incluyendo `zipKey`, `studyInstanceUID`, `queueId`, `callbackUrl`
3. Worker responde `202 Accepted` inmediatamente
4. Worker descomprime ZIP en segundo plano dentro de R2
5. Worker elimina ZIP original
6. Worker llama al callback PHP con resultado
7. PHP actualiza estado a `estudio_online`

## Desarrollo Local

```bash
# Ejecutar Worker localmente
wrangler dev

# Ver logs en tiempo real
wrangler tail
```

## Notas

- El Worker tiene límites de tiempo (30s gratis, 15min pago)
- Para ZIPs muy grandes (>500MB), considerar usar Durable Objects
- La librería `fflate` se carga desde CDN para evitar problemas de bundling
