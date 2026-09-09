# Alertas de transcripción en Informes Manager

Guía para usuarios del módulo **Gestión de Informes** (`informes-manager`).

---

## ¿Para qué sirve?

Cuando un informe tiene audios adjuntos, el sistema puede transcribirlos automáticamente (servidor Whisper). A veces la transcripción tarda más de lo normal o el servidor se cuelga.

En la **columna Audios** de la grilla de informes ahora se muestran avisos que indican si la transcripción está en curso, colgada o fallida, sin tener que entrar a otras pantallas (AI Informes o Auditoría).

---

## Dónde se ve

### 1. Grilla de informes — columna **Audios**

Junto al contador de audios (ej. `2 audios`) puede aparecer un badge adicional:

| Badge | Significado | Qué hacer |
|-------|-------------|-----------|
| **Transcribiendo** (amarillo, icono girando) | El audio está en cola o procesándose. Es normal los primeros minutos. | Esperar. Se actualiza solo cada ~30 segundos. |
| **TX colgada** (rojo, triángulo de alerta) | Lleva **3 minutos o más** en procesamiento sin completar, o **5 minutos o más** esperando en cola. Posible fallo del servidor de transcripción. | Abrir el informe y usar **Reintentar transcripción** (ver abajo). Si persiste, avisar a soporte/técnico. |
| **TX fallida** (rojo, cruz) | La transcripción falló tras reintentos automáticos. | Abrir el informe y reintentar manualmente. |
| **X/Y TX** (amarillo) | Solo algunos audios del informe están transcritos. | Revisar cada audio en el modal de edición. |
| *(sin badge extra, solo verde/gris)* | Todos transcritos o sin audios pendientes de TX. | Nada que hacer. |

El badge verde **N audios** sigue indicando cuántos audios activos tiene el informe (como antes).

---

### 2. Modal de edición del informe — sección Audios

Al editar o ver un informe con audios, cada tarjeta de audio puede mostrar:

- **Transcribiendo...** — en curso
- **TX colgada (N min)** — posible bloqueo
- **TX fallida**
- Botón verde de transcripción — solo si ya hay texto transcrito
- Botón **Reintentar** (flecha circular) — en audios colgados o fallidos

---

## Cómo reintentar una transcripción

1. Abrir el informe desde Informes Manager (editar o ver).
2. En la lista de audios, localizar el que muestra **TX colgada** o **TX fallida**.
3. Pulsar el botón **Reintentar** (icono de flecha circular) **una sola vez**.
4. El botón se desactiva unos **45 segundos** (anti doble-clic). Si el audio sigue sin transcribir, se reactiva solo para poder reintentar.
5. El sistema vuelve a encolar el audio. El badge de la grilla debería pasar a **Transcribiendo**.
6. Esperar 1–3 minutos (según duración del audio). Si completa, aparecerá el botón para ver la transcripción.

> **Audios ya transcriptos no se vuelven a enviar.** Si el audio ya tiene texto, no aparece Reintentar; si se intenta igual, el sistema lo omite.

> No es necesario permiso de auditoría para reintentar desde aquí; basta con poder acceder al informe.

---

## Qué **no** genera alerta (falsos positivos evitados)

- Audios de **grabadora móvil** aún no enviados al informe (estados internos `en_papelera` / `listo_workspace`).
- Informes **sin audios** (columna en gris: `0 audios`).

---

## Actualización automática

Si hay informes con transcripción pendiente o colgada en la página actual, la grilla **consulta el estado cada 30 segundos** y actualiza los badges sin recargar toda la página.

Para ver cambios inmediatos después de un despliegue del sistema, recargar la página (F5 o Ctrl+F5).

---

## Si el problema persiste

Indicar a soporte técnico:

- ID del informe
- ID o nombre del audio afectado
- Hora aproximada en que se subió el audio
- Si el badge dice **TX colgada** o **TX fallida**

El equipo técnico puede revisar cola, worker cron y servidor Whisper (ver manual técnico).

---

## Pantallas relacionadas (referencia)

| Pantalla | Uso |
|----------|-----|
| **AI Informes** | Vista global de cola y transcripciones bloqueadas |
| **Audit Manager → Audios** | Auditoría detallada por audio, reencolado masivo |
| **Informes Manager** | Vista del día a día con alertas en columna Audios *(este documento)* |

---

*Última actualización: 2026-09-02*
