# Health del servidor de transcripción (Whisper)

## ¿Qué es?

Antes de **enviar** audios a transcribir, el portal consulta el estado del servidor Whisper (`/health`). Así se evita encolar trabajos cuando la GPU/API está caída.

Los **audios se guardan igual**; solo se bloquea el envío a la cola de transcripción si el servidor no está listo.

---

## ¿Qué significa cada estado?

| Estado | Qué ves | Qué hacer |
|--------|---------|-----------|
| **TX OK** | Badge verde en Informes Manager / “OK” al Probar en Configuración | Nada; se puede transcribir |
| **TX degradado** | Badge amarillo / aviso al finalizar o reintentar | Se puede enviar, pero puede ir más lento (GPU caída, cola larga, CPU). Avisar a soporte si se prolonga |
| **TX caído** | Badge rojo / toast al finalizar o al Reintentar | No se envía a Whisper. El audio queda guardado. Reintentar más tarde o avisar a soporte |

---

## ¿GPU o CPU? (modo de operación)

Desde septiembre 2026, el sistema muestra claramente con qué dispositivo está transcribiendo:

- **GPU** (modo óptimo): Transcripciones rápidas, latencia mínima
- **CPU** (modo fallback): Transcripciones más lentas (~2x), pero **misma precisión**

**¿Dónde verlo?**
1. **Configuración → AI Informes → Probar**: muestra el dispositivo y razón
2. **Script de monitoreo** (solo para soporte/administración): `scripts/monitorear-whisper.sh`

**¿Es normal ver CPU?**
- Si la GPU no está disponible (servidor sin GPU, driver CUDA, etc.), el sistema usa CPU automáticamente
- **No se pierden funciones**, solo va más lento
- Si persiste y debería haber GPU disponible, avisar a soporte

---

## ¿Dónde aparece?

1. **Configuración → AI Informes → Probar** (whisper-cli): muestra OK / Degradado / Caído según `ready` y `status`.
2. **Informes Manager**: badge **TX OK / degradado / caído** (se actualiza ~cada 60 s).
3. **Workspace** al finalizar informe: si no se pudo encolar, toast de aviso (el audio ya está guardado).
4. **Reintentar** transcripción en el modal de audios: si está caído, error claro.

---

## Preguntas frecuentes

### ¿Si está caído pierdo el audio?
No. El audio queda en el informe. Solo no entra a la cola de Whisper hasta que el servidor vuelva o pulses Reintentar.

### ¿Quién configura la URL?
Administración / root en **Configuración → AI Informes** (`URL whisper-cli`). No hace falta poner la IP a mano en el código.

### Si persiste, indicar a soporte…
- Hora aproximada y si el badge decía “caído” o “degradado”
- Si al Probar en Configuración falla
- IDs de informe/audio afectados

---

*Última actualización: 2026-09-03*
