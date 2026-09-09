# SLA de estudios recibidos (manual de usuario)

Última actualización: 2026-09-03

## Qué es

Monitorea que un estudio que ingresó al **PACS local** tenga su informe **publicado en PACS** dentro de un plazo (por defecto **72 horas**).

## Cómo se apaga / enciende

En **Configuración → Estudios Recibidos**:

- `sla_activo` = **No** → sin alertas operativas (recomendado al desplegar).
- Horas default y aviso «por vencer» (default 24 h antes).
- Plantillas por modalidad (CSV).
- Token y snippet Lua para Orthanc OnStableStudy.

## Quién lo ve

Cuentas con permiso **Monitorear SLA estudios** (`monitorearSlaEstudios`) en Gestión de Informes: contadores **SLA VENCIDOS** / **SLA POR VENCER** y modal al hacer click.

## Cumplido vs trazabilidad

- **Cumplido:** informe publicado en PACS.
- La traza (llegada → asignado → dictado → transcripto → firmado → publicado) queda registrada para métricas futuras. El dictado puede venir de audio o solo de informe (médicos que dictan fuera de la plataforma).

## Acciones en el modal

- Ver informe (si existe).
- Override de horas para un estudio.
- Excluir del SLA con motivo.
