# Auditoría MPPS / Equipos

Guía para usuarios de la sección **Auditoría** → pestaña **MPPS / Equipos**.

---

## ¿Para qué sirve?

Los equipos de imagen (tomógrafo, resonador, rayos, etc.) pueden avisar al sistema cuando **empiezan** y cuando **terminan** un estudio. Ese aviso se llama MPPS.

Esta pestaña muestra esos avisos y los compara con:

1. La **lista de trabajo** (worklist / turnos agendados)
2. Las **imágenes guardadas** en el PACS

Así podés detectar estudios hechos sin turno, equipos usados sin imágenes almacenadas, u órdenes que nunca se realizaron.

---

## ¿Dónde lo veo?

1. Entrar a **Auditoría** (menú lateral).
2. Abrir la pestaña **MPPS / Equipos**.

Si ves un badge **Demo**, estás en modo demostración (`source=mock`). El refresh normal consulta **Orthanc** en vivo: si no hay avisos MPPS, la tabla queda vacía.

---

## ¿Qué significan los números de arriba?

| Tarjeta | Significado |
|---------|-------------|
| **Eventos** | Cantidad de avisos listados (o de la demo). |
| **En progreso** | Estudios que el equipo marcó como en curso. |
| **Fuera de worklist** | Se usó el equipo sin coincidencia clara con un turno agendado. |
| **Fantasmas** | El equipo dijo que terminó el estudio, pero no hay imágenes en el PACS. |

---

## ¿Qué significan los diagnósticos?

| Diagnóstico | Significado | Qué hacer |
|-------------|-------------|-----------|
| **Normal** | Había turno, el equipo avisó y hay imágenes. | Nada; flujo correcto. |
| **Fuera de worklist** | Estudio hecho a mano en la consola (urgencia, guardia, etc.). | Avisar a admisión/caja para dar de alta la orden y facturar. |
| **Fantasma** | El equipo se usó y avisó el fin, pero no llegaron imágenes al PACS. | Avisar a soporte/técnico; revisar si borraron imágenes o falló el envío. |
| **Ausente** | Había orden en la lista, pero el equipo nunca avisó inicio. | Paciente no se presentó o no se usó la sala; revisar agenda. |
| **Pendiente imágenes** | Hay aviso del equipo pero aún no se ven imágenes (puede ser reciente). | Esperar unos minutos; si no aparecen, tratar como fantasma. |

---

## Columnas de la tabla

- **Estado**: En progreso / Completado / Interrumpido (lo que reportó el equipo).
- **Paciente**: Nombre e ID que envió la consola.
- **Accession**: Número de orden/acceso (debe coincidir con el turno si vino de worklist).
- **Modalidad / Sala**: Tipo de equipo y consola.
- **Inicio / Fin**: Hora que registró el equipo (no la hora de actualización de la pantalla).
- **Worklist**: Si se encontró el turno por accession.
- **PACS**: Si ya hay estudio de imágenes asociado.
- **Diagnóstico**: Resumen automático (tabla de arriba).

---

## ¿Qué no es un error?

- Un retraso de hasta medio minuto en ver el cambio de estado es normal si el sistema revisa avisos periódicamente; la **hora de inicio/fin** del equipo sigue siendo la correcta.
- El badge **Demo** no indica falla: solo indica que aún no hay datos reales.

---

## Si persiste, indicar a soporte…

- Número de accession (si hay)
- Nombre / ID del paciente
- Modalidad y sala
- Hora de inicio/fin que muestra la grilla
- Diagnóstico (Normal, Fantasma, etc.)
- Captura de pantalla de la pestaña

---

*Última actualización: 2026-09-02*
