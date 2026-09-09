# Copia de antecedentes entre estudios (mismo paciente / misma fecha)

Implementación técnica (2026-09-02) en **estudios-manager**.

---

## Objetivo

Permitir replicar notas y archivos de `study_antecedents` / `study_antecedents_files` desde un estudio origen hacia otros estudios del **mismo PatientID DICOM** y la **misma fecha de estudio** (día calendario), sin cambiar el modelo “antecedentes por study_id Orthanc”.

---

## Alcance UX

1. Tras **Guardar Antecedentes** → se marca `antecedentsSavedForCopy = true`, se muestra el botón (si hay hermanos) y `confirm` opcional → abre selector.
2. Botón **Copiar a otros del mismo día** + hint `#copyAntecedentsSameDayHint`:
   - Si hay hermanos: botón **siempre visible**; desactivado hasta `antecedentsSavedForCopy`; hint en amarillo (“Guardá para activar”) o azul (“Ya podés copiar”).
   - Si no hay hermanos: botón y hint ocultos.
3. Selector: checkboxes, badge si el destino ya tiene antecedentes, modo notas overwrite/append.

Al abrir el modal se resetea `antecedentsSavedForCopy = false`.

Los hermanos se buscan en `this.studies` (lista cargada en el cliente), no con C-FIND adicional.

---

## Archivos

| Archivo | Cambio |
|---------|--------|
| `api/copy_antecedents_to_studies.php` | **Nuevo** — API de copia |
| `assets/js/estudios-manager.js` | Helpers, modal selector, sugerencia post-guardado, botón |
| `estudios-manager.html` | Cache bust JS `?v=202609021230` |

Sin migración SQL.

---

## API

**POST** `api/copy_antecedents_to_studies.php`

```json
{
  "source_study_id": "orthanc-uuid",
  "target_study_ids": ["id1", "id2"],
  "created_by": 123,
  "notes_mode": "overwrite",
  "copy_files": true
}
```

| Campo | Descripción |
|-------|-------------|
| `notes_mode` | `overwrite` (default) o `append` |
| `copy_files` | default `true`; duplica archivos en `uploads/antecedents/` |

Comportamiento:

1. Carga último registro de antecedentes del origen (`ORDER BY id DESC LIMIT 1`) + archivos.
2. Por cada destino (máx. 50, excluye el origen):
   - Upsert notas según modo.
   - Si `copy_files`: copia física con nombre `copy_*` y nueva fila en `study_antecedents_files`.
3. No elimina archivos previos del destino.

Respuesta: `{ success, copied, skipped, details[], message }`.

---

## Frontend (`DerivacionesManager`)

| Método | Rol |
|--------|-----|
| `normalizeStudyDateKey(dateStr)` | Normaliza a `YYYYMMDD` (acepta YYYYMMDD, YYYY-MM-DD, DD/MM/YYYY) |
| `findSiblingStudiesSameDay(study)` | Filtra `this.studies` por mismo `patient_id` (case-insensitive) y misma fecha |
| `updateCopyAntecedentsButtonVisibility(study)` | Muestra/oculta botón del footer |
| `maybeSuggestCopyAntecedents(studyId)` | Confirm post-guardado |
| `openCopyAntecedentsModal(studyId, siblings?)` | UI selector + estado batch vía `study_antecedents.php` |
| `executeCopyAntecedents(studyId)` | POST a la API de copia |

Hook post-guardado: `emergencySaveAntecedents` → tras `loadExistingAntecedents` / `loadAntecedentsStatus` llama `maybeSuggestCopyAntecedents`.

---

## Criterios de “hermano”

```
patient_id trim+lower igual
AND dateKey(origen) === dateKey(destino)
AND id distinto
```

Fecha desde `study.date || study.study_date`.

---

## Riesgos / limitaciones

- Solo estudios presentes en la grilla/caché actual.
- PatientID inconsistente entre estudios → no se agrupan.
- Estudios remotos sin Orthanc ID usable pueden fallar al copiar (clave distinta).
- Tabla `study_antecedents` sin UNIQUE en `study_id` (histórico); la API usa el registro más reciente.

---

## Pruebas sugeridas

1. Dos estudios mismo PatientID y misma fecha → botón visible + sugerencia al guardar.
2. Estudios misma persona otra fecha → no aparecen.
3. Destino sin antecedentes: marcado por defecto; copia notas + archivos.
4. Destino con notas: overwrite vs append.
5. Destino con archivos previos: se conservan y se agregan los copiados.
6. Un solo estudio del paciente ese día → sin botón / sin sugerencia.

---

*Última actualización: 2026-09-02*
