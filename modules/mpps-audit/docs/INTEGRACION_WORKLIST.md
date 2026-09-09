# Integración Worklist — MPPS Audit

## Relación MWL ↔ MPPS

| Dirección | Servicio | Uso |
|-----------|----------|-----|
| RIS → Equipo | Modality Worklist (MWL) | Qué pacientes atender |
| Equipo → RIS/PACS | MPPS | Qué se hizo realmente |

Este módulo **no** genera worklist; solo **cruza** eventos MPPS con órdenes existentes.

## Clave de cruce

Campo principal: **`accession_number`**

1. Extraer Accession del MPPS (`0008,0050` o secuencia de attributes).
2. Buscar en `worklist.accession_number`.
3. Resultado → `worklist_match`:

| Valor | Significado |
|-------|-------------|
| `matched` | Accession encontrado en worklist |
| `orphan` | Accession presente pero no existe en BD / genérico (ej. EMERGENCIA) |
| `no_accession` | Vacío o ausente |
| `unknown` | Aún no evaluado |

Si hay match: rellenar `worklist_id` (FK opcional creada por `install.php` si existe tabla `worklist`).

## Acciones futuras (entrega 2)

| Evento MPPS | Acción sugerida en worklist |
|-------------|----------------------------|
| IN PROGRESS + matched | `status = 'in_progress'` |
| COMPLETED + matched | `status = 'completed'` (+ UID PACS si aplica) |
| DISCONTINUED + matched | Política local (dejar abierta / cancelled) |
| Sin match | No tocar worklist; marcar `orphan` / alerta |

## Sin Worklist en la instancia

El módulo funciona igual: `install.php` omite la FK y todos los matches quedan `unknown`/`orphan`/`no_accession` según datos del MPPS. El cruce PACS por `StudyInstanceUID` sigue siendo válido.

## Relación con “Completados sin UID PACS” en Worklist

La UI de Worklist ya cuenta órdenes `completed` sin `pacs_study_instance_uid`. MPPS agrega la capa **equipo usó / no usó** aunque nadie haya marcado la orden: útil para fantasmas y no agendados.
