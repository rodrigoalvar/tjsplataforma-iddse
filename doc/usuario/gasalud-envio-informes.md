# Envío de informes PDF a Gasalud

Última actualización: 2026-09-09

## ¿Para qué sirve?

Cuando un médico **genera y publica un informe en la plataforma**, el sistema puede enviar el **PDF** a Gasalud para que quede asociado al estudio (mismo accession / DNI).

**No** se envían los PDF que llegan por **API de informes recibidos** (origen externo), para no crear un ciclo de ida y vuelta.

## Qué configurar

1. Ir a **Configuración → Envío Gasalud**.
2. Completar (si no están ya):
   - URL Informes: `http://192.168.0.149:8325/api/v2/Informes`
   - URL Login: `http://192.168.0.149:8325/api/v2/Usuarios/Login`
   - Auth: **Login API**
   - Usuario / contraseña de API Gasalud
   - Prestador: **Worklist / HL7 PV1 (matrícula)**
   - Cuándo enviar: **Tras publicar en PACS** (recomendado)
3. Pulsar **Validar config** y luego **Probar login**.
4. Cuando esté listo, poner **Activar envío = Sí** y **Guardar**.

## Cuándo se envía

| Opción | Qué pasa |
|--------|----------|
| Tras publicar en PACS | Después de un envío OK a Orthanc/PACS de un informe de plataforma (ahí ya existe el PDF). |
| Al pasar a Finalizado | Si el informe ya tiene PDF en disco al finalizar. |
| Solo manual | No automático; se usa la API interna de envío manual (soporte/admin). |

## Datos que se mandan

| Campo | Origen |
|-------|--------|
| Paciente | DNI del paciente del informe |
| AcessionNumber | Accession del estudio / informe |
| Nombre | Nombre del archivo PDF |
| Archivo | El PDF |
| Prestador | Matrícula/ID del PV1 guardada en worklist (HL7) |
| Descripcion | Descripción del estudio o título del informe |
| Tipo | `pdf` |

## Si algo falla

- Revisar que el estudio haya llegado por **HL7** a worklist (para el Prestador).
- Que el informe tenga **PDF** y **accession**.
- Que el envío esté **activo** y el login responda OK.
- Indicar a soporte: hora, accession, ID de informe y si falló el login o el POST Informes.

## Qué no es un error

- Un informe **externo / recibido por API** no se envía a Gasalud: es esperado.
- Con el envío **apagado**, no se manda nada aunque se publique en PACS.
