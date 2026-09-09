# Worker Pool - Guía Rápida

## ¿Qué cambió?

El sistema ahora usa un **Worker Pool** que mantiene siempre N uploads activos simultáneamente, en lugar de procesar en lotes fijos.

## Configuración Rápida

### 1. Ajustar Concurrencia

Edita `.env` del módulo:

```env
# Recomendado: 10-20 para la mayoría de casos
R2_UPLOAD_CONCURRENCY=15
```

### 2. Probar

1. Encuela un estudio
2. Observa la velocidad de upload
3. Deberías ver velocidades más altas y consistentes

## Valores Recomendados

| Tu Situación | Concurrencia |
|--------------|--------------|
| Conexión lenta (< 10 Mbps) | 5-8 |
| Conexión normal (10-50 Mbps) | 10-15 |
| Conexión rápida (50-100 Mbps) | 15-20 |
| Conexión muy rápida (> 100 Mbps) | 20-30 |

## Verificación

### ¿Funciona el Worker Pool?

**Síntomas de que funciona:**
- ✅ Velocidad de upload más alta y consistente
- ✅ Progreso fluido (sin pausas largas)
- ✅ Múltiples uploads activos simultáneamente

**Síntomas de problemas:**
- ❌ Velocidad igual o peor que antes
- ❌ Errores de memoria
- ❌ Timeouts frecuentes

### Solución a Problemas

**Si hay errores de memoria:**
- Reducir `R2_UPLOAD_CONCURRENCY` (ej: de 15 a 10)

**Si hay timeouts:**
- Reducir `R2_UPLOAD_CONCURRENCY`
- Verificar recursos del servidor

**Si la velocidad no mejora:**
- Verificar ancho de banda disponible
- Monitorear CPU durante uploads
- Ajustar según recursos disponibles

## Documentación Completa

Ver `docs/WORKER_POOL_UPLOAD.md` para detalles técnicos completos.
