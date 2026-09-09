# Health check de Whisper antes de encolar

## Objetivo

Consultar `{whisper_cli_api_url}/health` (o health del whisper-server) **antes** de insertar en `ai_transcription_queue` o llamar a Whisper en modo directo. URL desde `ai_config`, nunca hardcodeada.

Contrato esperado (whisper-cli):

```json
{
  "ready": true,
  "status": "ok" | "degraded" | "down",
  "operation_mode": {
    "device": "GPU" | "CPU",
    "reason": "GPU disponible / GPU no disponible - usando CPU i9 como fallback",
    "performance": "optimal" | "good",
    "note": "Información sobre rendimiento y precisión"
  },
  ...
}
```

| `ready` / `status` | Comportamiento portal |
|--------------------|------------------------|
| `ready` + `ok` | Encolar / procesar |
| `ready` + `degraded` | Encolar con `warning` en JSON; UI toast/badge amarillo |
| `!ready` / `down` / HTTP 503 / timeout | **No** encolar (HTTP 503 en APIs de enqueue/requeue); upload/attach guardan audio y omiten cola |

### Campo `operation_mode` (desde v2026.09)

Indica el dispositivo de procesamiento actual:

- **`device`**: `"GPU"` (óptimo) o `"CPU"` (fallback)
- **`reason`**: Explica por qué se usa ese dispositivo
- **`performance`**: `"optimal"` (GPU) o `"good"` (CPU)
- **`note`**: Información sobre impacto en rendimiento (ej: "Transcripciones en CPU: latencia ~2x, precisión idéntica")

**Uso operativo:**
- Si `device = "CPU"` cuando se esperaba GPU → investigar disponibilidad de GPU/CUDA
- El modo CPU es funcional pero ~2x más lento; la precisión es idéntica
- Para diagnóstico: consultar `/health` o usar script `scripts/monitorear-whisper.sh`

Timeout del health: **4 s**.

---

## Archivos

| Pieza | Ruta |
|-------|------|
| Helper PHP | `api/transcription_health.php` → `transcriptionCheckHealth`, `transcriptionAssertReadyToEnqueue` |
| Cliente | `utils/WhisperClient.php` → `checkHealth()`, `testConnection()` enriquecido |
| Proxy UI | `api/ai-informes.php?action=transcription-health` |
| Test config | `api/ai-informes-config.php?action=test-whisper-cli` |
| Script monitoreo | `scripts/monitorear-whisper.sh` → muestra `operation_mode` al inicio |
| Bloqueo enqueue | `api/audios/enqueue.php`, `requeue-transcription.php` |
| Soft-skip | `api/audios/upload.php`, `api/informes/attach-audio.php` |
| Cola AI | `handleAddToQueue`, `handleQueueBatch`, `handleTranscribeAudio`, `handleProcessQueue` |
| Worker | `workers/transcription-queue-worker.php` (sale si no `allow_enqueue`) |
| JS helper | `assets/js/transcription-health.js` |
| UI | `configuracion.js` (Probar), `informes-manager.js` (badge), `workspace.html` (toasts) |

---

## Respuestas típicas

**Down (enqueue):**

```json
{
  "success": false,
  "error": "Servidor de transcripción no disponible…",
  "enqueued": 0,
  "transcription_health": { "status": "down", "allow_enqueue": false }
}
```

**OK / degradado:**

```json
{
  "success": true,
  "enqueued": 2,
  "warning": null,
  "transcription_health": { "status": "ok", "degraded": false }
}
```

---

## Notas

- Si Whisper está caído, los jobs **ya pending** en cola no se procesan (worker sale); no se pierden.
- `auto_transcribe_enabled` sigue siendo prerequisito aparte del health.
- Manual usuario: `doc/usuario/transcripcion-health-check.md`

---

## Script de monitoreo

Ruta: `scripts/monitorear-whisper.sh`

Script de diagnóstico que consulta el endpoint `/health` y muestra el estado de forma legible. Destaca el modo de operación (GPU/CPU) al inicio:

**Ejemplo de salida:**

```
🔧 MODO DE OPERACIÓN:
  ⚠️  Dispositivo: CPU i9
  📝 Razón: GPU no disponible - usando CPU i9 como fallback
  ℹ️  Transcripciones en CPU (latencia ~2x, precisión idéntica)

📊 Estado: ok
✅ Ready: true
...
```

**Uso:**
```bash
./scripts/monitorear-whisper.sh
```

**Interpretación:**
- **Dispositivo: GPU** → modo óptimo, latencia mínima
- **Dispositivo: CPU** → fallback funcional, latencia ~2x (precisión idéntica)
- Si ve CPU cuando se esperaba GPU → verificar CUDA, drivers NVIDIA, disponibilidad de GPU

---

*Última actualización: 2026-09-03*
