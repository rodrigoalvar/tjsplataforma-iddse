# Firma médica de informes (manual de usuario)

Última actualización: 2026-09-03

## ¿Qué es el circuito de firma?

Después de transcribir un informe **de plataforma**, pasa a **Transcripto** para que el médico firme. Los PDF **recibidos por API** (externos) se vinculan como **Finalizado** y pueden enviarse a PACS automáticamente (sin cola de firma).

Orden de estados:

1. **Borrador** — se está redactando (solo informes de plataforma).
2. **Transcripto** — listo para que el médico revise y firme.
3. **Firmado** — el médico dio el visto bueno (rúbrica insertada).
4. **Finalizado** — listo para publicar en PACS.
5. Envío a PACS (botón verde, igual que antes).

## Tipos de informe

| Tipo | ¿Se puede editar el texto? | Al firmar |
|------|----------------------------|-----------|
| Creado en la plataforma (editor / workspace) | Sí | Se agrega la firma y el sello al final del texto |
| PDF externo (API o adjunto) | No; solo se visualiza el PDF | Se agrega una página de sello al PDF |

## ¿De quién es el informe?

- Si el estudio está **asignado** en Estudios Manager, el dueño del informe (y quien debe firmar) es ese médico.
- Los PDF que llegan por API **no tienen dueño hasta vincularlos** a un estudio. El transcriptor vincula el PDF al estudio; no elige médico en la bandeja de recibidos.
- Si el estudio no está asignado, asigne el estudio primero; verá el aviso **Sin médico asignado**.

## Qué hace el médico

1. En el **Dashboard**, pulse el contador **PARA FIRMAR**: se abre un modal con la lista (más nuevos primero), filtros de fecha y acciones **Ver**, **Editar** (solo plataforma) y **Firmar** (aprueba → estado Firmado).
2. **Editar** abre **Gestión Informes** con ese informe. **Firmar** puede hacerse desde el modal del dashboard o desde el manager.
3. Configure su rúbrica en **Mi firma** (Gestión Informes: subir imagen o capturar con QR desde el teléfono) y el texto del sello antes de firmar si aún no la tiene.

## Qué hace el transcriptor / operador

1. Deja el informe **de plataforma** en **Transcripto** cuando el texto está listo.
2. Vincula PDF API al estudio correcto (queda **Finalizado**; con auto-PACS activo se publica solo).
3. Tras la firma del médico (plataforma), cambia a **Finalizado** si hace falta y envía a PACS.

## Si algo falla

- “Configure su rúbrica”: complete **Mi firma** antes de firmar.
- No aparece en PARA FIRMAR: verifique permiso `firmarInformes`, que el estudio esté asignado a usted y el estado sea Transcripto.
- PDF externo sin botón Guardar: es normal; solo puede firmar o visualizar.

Si el problema continúa, indique a soporte: ID del informe, paciente, estado actual y si es PDF externo o de plataforma.
