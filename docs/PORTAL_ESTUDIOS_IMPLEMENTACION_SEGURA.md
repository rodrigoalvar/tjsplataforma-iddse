# Implementacion segura: Portal de Estudios (paciente.html)

Este documento define el plan minimo para avanzar con el Portal de Estudios sin romper la funcionalidad actual.

## Objetivo

Unificar en `paciente.html` los modos de consulta/apertura:

- Local (Orthanc)
- Remoto (DIMSE/DICOMweb via nodos PACS)
- Cloud Storage (R2)

Con seleccion de visor por dispositivo (mobile/desktop) y politica de prioridad (`auto` / `strict`).

## Principio de seguridad

Todo se implementa con **feature flag apagado por defecto**.

- Si flag = `0`: se mantiene 100% el flujo actual de `paciente.html`.
- Si flag = `1`: se habilita el nuevo enrutamiento por politica.

## Decisiones de producto a fijar (antes de codigo de riesgo)

1. Fuente de verdad de configuracion:
   - Reutilizar Study Routing existente como base (recomendado), con override de portal opcional.
2. Orden de prioridad en `auto`:
   - Recomendado: `R2 -> Local -> Remoto`.
3. Regla en `strict`:
   - Sin fallback automatico, con mensaje explicito.
4. Fallback de visor:
   - Recomendado: si visor no soporta origen/nodo, fallback a `UDV` solo en `auto`.

## Configuracion propuesta (fase 0, sin impacto)

Categoria: `portal_estudios`

- `portal_estudios_v2_enabled`: `0|1`
- `portal_estudios_listing_mode`: `local|remote|mixed`
- `portal_estudios_opening_mode`: `auto|strict`
- `portal_estudios_strict_node_id`: `<id>|''`
- `portal_estudios_use_r2`: `0|1`
- `portal_estudios_r2_priority`: `first|last`
- `portal_estudios_search_id_type`: `idpaciente|id_interno`
- `portal_estudios_viewer_desktop`: `UDV|StoneViewer|OHIF|Oviyam|VolView`
- `portal_estudios_viewer_mobile`: `UDV|StoneViewer|OHIF|Oviyam|VolView`
- `portal_estudios_show_source_badge`: `0|1`

## Fases de implementacion

### Fase 0 (sin impacto funcional)

- Agregar configuraciones de `portal_estudios` con defaults seguros.
- No cambiar el comportamiento de `paciente.html`.
- Agregar telemetria/log de estrategia calculada (solo debug).

### Fase 1 (backend paralelo)

- Crear endpoint nuevo para busqueda/apertura unificadas (sin usarlo aun en UI).
- Reutilizar:
  - `StudyRoutingService` para local/R2.
  - `study-history-manager` para remoto multi-nodo.
- Exponer payload estable con `sources[]`, `availability`, `open_strategy`.

### Fase 2 (frontend con flag)

- En `paciente.html`, usar endpoint nuevo solo si `portal_estudios_v2_enabled = 1`.
- Mantener flujo legacy si flag = `0`.
- Mostrar badges de origen (`R2`, `Local`, `Remoto`) opcional.

### Fase 3 (staging y rollout)

- Validacion en staging con casos reales.
- Produccion gradual (usuarios piloto).
- Rollback inmediato si aparece regresion.

## Matriz de decision (resumen)

### Opening mode = auto

- Si `use_r2=1` y estudio online en R2:
  - `r2_priority=first` => abrir R2
  - `r2_priority=last` => intentar local/remoto primero
- Si no aplica R2:
  - intentar local
  - luego remoto

### Opening mode = strict

- Abrir solo en origen permitido (R2/local/nodo remoto seleccionado).
- Si no existe en ese origen: no abrir y mostrar mensaje claro.

## Compatibilidad inicial de visores

- `UDV`: local + remoto DIMSE + R2
- `StoneViewer`: local/DICOMweb; en DIMSE puro usar fallback a UDV (solo `auto`)
- `OHIF` / `VolView`: habilitar cuando haya endpoint DICOMweb compatible
- `Oviyam`: usar si hay WADO-URI valido

## Go/No-Go checklist (obligatorio)

### A. Contrato y configuracion

- [ ] Flags de `portal_estudios` creados con defaults seguros.
- [ ] Orden de prioridad `auto` aprobado por producto.
- [ ] Regla `strict` aprobada (sin fallback).
- [ ] Politica de fallback de visor aprobada.

### B. Regresion funcional actual

- [ ] `paciente.html` legacy funciona igual con flag en `0`.
- [ ] Busqueda por `documento/idpaciente` funciona.
- [ ] Busqueda por `id_interno` funciona.
- [ ] Deep links (`?doc=` y `?id_interno=`) funcionan.
- [ ] Apertura de visor actual funciona.
- [ ] Descarga de estudios funciona.
- [ ] Apertura/descarga de informes PDF funciona.

### C. Nuevo flujo (flag en `1`)

- [ ] Modo `local` lista y abre correctamente.
- [ ] Modo `remote` lista y abre correctamente.
- [ ] Modo `mixed` sin duplicados por StudyInstanceUID.
- [ ] `auto` respeta prioridad configurada (R2/local/remoto).
- [ ] `strict` bloquea fallback y muestra mensaje correcto.
- [ ] Selector mobile/desktop respeta visor configurado.

### D. Operacion segura

- [ ] Logs incluyen estrategia final de apertura y fallback reason.
- [ ] Existe toggle de rollback inmediato (`portal_estudios_v2_enabled=0`).
- [ ] Validado en staging con datos reales.
- [ ] Rollout piloto ejecutado sin incidentes.

## Criterio de salida de la implementacion

Se considera lista para habilitacion general cuando:

1. Todos los items de checklist estan completos.
2. No hay regresiones en flujos legacy.
3. Se prueba rollback exitoso en menos de 5 minutos.
