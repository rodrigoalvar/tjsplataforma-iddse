# ⚠️ Worker de Cloudflare - DEPRECADO

Este Worker fue implementado pero **NO se utiliza** debido a limitaciones de tiempo de procesamiento en Cloudflare Workers.

## Razón de Deprecación

- **Límite de tiempo**: Workers tienen máximo 30 segundos (plan gratuito) o 15 minutos (plan pago)
- **ZIPs grandes**: Los estudios DICOM pueden generar ZIPs de 300+ MB que requieren más tiempo para descomprimir
- **Alternativa elegida**: Mejorar el método de upload por instancias en lugar de usar ZIPs

## Estado

- ✅ Código implementado y funcional
- ❌ No se utiliza en producción
- 📦 Archivos movidos a `archived/` para referencia futura

## Si se necesita en el futuro

Para reactivar este Worker, sería necesario:
1. Usar Durable Objects para procesamiento asíncrono sin límite de tiempo
2. O dividir ZIPs en partes más pequeñas
3. O usar un servicio externo para descompresión
