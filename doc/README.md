# Documentación del sistema TJSMEDICAL

Esta carpeta centraliza la documentación que se va construyendo a medida que el sistema evoluciona. El objetivo es mantener **dos líneas paralelas**:

| Carpeta | Audiencia | Contenido |
|---------|-----------|-----------|
| [`usuario/`](usuario/) | Médicos, administrativos, operadores | Qué ven en pantalla, qué significa cada aviso, qué hacer |
| [`tecnico/`](tecnico/) | Desarrolladores, soporte, administradores de sistema | Arquitectura, APIs, tablas, workers, umbrales, archivos |

> **Nota:** Existe también la carpeta [`docs/`](../docs/) con documentación histórica del proyecto (permisos, PACS, modales, etc.). Esta carpeta `/doc` es el punto de entrada unificado que iremos completando y consolidando.

---

## Visión: agente de ayuda con IA

La separación **usuario / técnico** no es arbitraria: prepara un **sistema de ayuda asistido por agente de IA** para quienes usan el portal.

| Capa | Rol en el agente de ayuda |
|------|---------------------------|
| **`usuario/`** | **Base de conocimiento del agente.** Lenguaje claro, pasos concretos, “qué significa este aviso”, “qué hago si…”. El agente responde al operador sin exponer detalles internos. |
| **`tecnico/`** | **Referencia para soporte avanzado y mantenimiento.** No se indexa directamente al usuario final salvo escalamiento; sirve para que soporte/dev alimenten al agente, depuren respuestas o resuelvan casos que el manual de usuario no cubre. |

### Próximos pasos previstos (no implementados aún)

1. Ir completando `usuario/` por módulo (informes-manager, workspace, estudios, etc.).
2. Mantener `tecnico/` alineado con cada cambio de código estable.
3. Indexar / embeber los manuales de **usuario** como contexto del agente (RAG o similar).
4. Definir reglas del agente: solo citar manual usuario, escalar a soporte si el caso es técnico (servidor caído, permisos, BD).
5. Opcional: enlazar pantallas del UI con anclas en la doc (`#tx-colgada`, módulo, rol).

### Criterios al escribir manual de usuario (pensando en IA)

- Títulos y secciones **pregunta-respuesta** cuando aplique (“¿Qué significa TX colgada?”).
- Pasos numerados y acciones concretas (“Pulsar Reintentar”).
- Evitar jerga de implementación (nombres de tablas, archivos PHP).
- Incluir **qué no es un error** (falsos positivos) para reducir alucinaciones del agente.
- Una sección **“Si persiste, indicar a soporte…”** con datos mínimos a reportar.

---

## Índice actual

### Manual de usuario

| Documento | Descripción |
|-----------|-------------|
| [Alertas de transcripción en Informes Manager](usuario/informes-manager-alertas-transcripcion.md) | Badges en columna Audios, modal de edición, reintentar TX |
| [Copiar antecedentes (mismo día)](usuario/estudios-manager-copiar-antecedentes.md) | Copiar notas/archivos a otros estudios del paciente en la misma fecha |
| [Copiar plantillas a médicos](usuario/plantillas-copiar-a-medicos.md) | Transcriptor asigna plantilla copiándola a Médicos Informantes |
| [Health del transcriptor (Whisper)](usuario/transcripcion-health-check.md) | Badge TX OK/degradado/caído; audios se guardan aunque Whisper esté caído |
| [Recepción HL7 en Worklist](usuario/worklist-hl7-recepcion.md) | Activar MLLP, puerto, selector Prestador |
| [Envío de informes PDF a Gasalud](usuario/gasalud-envio-informes.md) | Configurar envío automático/manual; solo informes de plataforma |
| [Firma médica de informes](usuario/informes-firma-medica.md) | Circuito Transcripto→Firmado→Finalizado→PACS; Mi firma; avisos dashboard |
| [SLA estudios recibidos](usuario/sla-estudios-recibidos.md) | Plazo 72 h desde PACS local hasta publicación; contadores en informes-manager |
| [Auditoría MPPS / Equipos](usuario/mpps-auditoria-equipos.md) | Pestaña en Auditoría: avisos de inicio/fin del equipo, fantasmas y fuera de worklist |

### Manual técnico

| Documento | Descripción |
|-----------|-------------|
| [Arquitectura de transcripción de audios](tecnico/transcripcion-audios-arquitectura.md) | Cola, worker, Whisper, tablas, flujo completo |
| [Health check antes de encolar](tecnico/transcripcion-health-check.md) | `/health` ready/status, proxy, bloqueo enqueue/worker |
| [Módulo HL7 → Worklist](tecnico/hl7-worklist-modulo.md) | Receptor MLLP, parser ORM^O01, config, systemd |
| [Estado TX en Informes Manager](tecnico/informes-manager-estado-transcripcion.md) | Implementación de alertas, APIs, polling, criterios de bloqueo |
| [Copia de antecedentes entre estudios](tecnico/estudios-manager-copiar-antecedentes.md) | API copy, filtro mismo día, UI selector |
| [Copia de plantillas a médicos](tecnico/plantillas-copiar-a-medicos.md) | copy-to-users, creado_por, copiado_de |
| [Circuito firma médica / PACS](tecnico/informes-circuito-firma-pacs.md) | ENUM transcripto, sign.php, dueño por asignación, overlay PDF, QR firma |
| [SLA estudios recibidos](tecnico/sla-estudios-recibidos.md) | local_arrived_at, plantillas, webhook, timeline, permiso monitorearSlaEstudios |
| [Envío PDF Gasalud](tecnico/gasalud-envio-informes.md) | Login API, multipart Archivo, trigger al_pacs, anti-loop externo |
| [Logs HL7 en Configuración](tecnico/hl7-worklist-modulo.md) | Pestaña Logs → sección HL7/MLLP (`modules/hl7-worklist/api/logs.php`) |
| [Arquitectura auditoría MPPS](tecnico/mpps-auditoria-arquitectura.md) | Módulo mpps-audit, tablas, APIs, Orthanc, mock entrega 1 |

---

## Convenciones

- Cada funcionalidad nueva debería tener (cuando esté estable):
  1. Entrada en este README
  2. Página en `usuario/` si afecta la interfaz
  3. Página en `tecnico/` si toca backend, BD o integraciones
- Fecha de última actualización al pie de cada documento
- Referencias a rutas de archivos relativas al repo (`/var/www/tjsiddse/...`)

---

*Última actualización: 2026-09-02*
