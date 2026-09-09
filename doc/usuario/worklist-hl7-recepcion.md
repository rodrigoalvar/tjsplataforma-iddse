# Recepción HL7 en Worklist

## ¿Para qué sirve?

Algunos sistemas externos (RIS) envían **órdenes de estudio** por HL7 a la plataforma. Esas órdenes aparecen en la **Worklist** (lista de trabajo), igual que cuando llegan por archivos `.txt`.

## Dónde se configura

1. Ir a **Configuración**.
2. Abrir la pestaña **Worklist**.
3. Buscar la sección **HL7 / MLLP**.

### Campos importantes

| Campo | Qué hacer |
|-------|-----------|
| **Canal de ingesta Worklist** | Elegí **Solo .txt**, **Solo HL7**, **Ambos** o **Ninguno** |
| **Puerto TCP** | El acordado con el sistema externo (ej. 2575), si usás HL7 |
| **Campo Prestador** | De dónde sale el ID del médico (PV1-8, PV1-7…). Si no saben, dejar PV1-8 |
| **Carpetas inbox** | Normalmente no hace falta cambiarlas |

> Si elegís **Ambos**, la misma Accession Number **no crea dos filas**: se actualiza el mismo estudio en Worklist.

Después de guardar, pulsar **Probar listener** (si el canal incluye HL7). Debe decir que está **escuchando**.

## ¿Qué ve el operador?

Los estudios llegan a la pantalla **Worklist** con paciente, accession, modalidad y hora programada. No hace falta abrir mensajes HL7.

## Si no llegan estudios

1. Verificar que HL7 esté **habilitado** y guardado.
2. Pulsar **Probar listener**.
3. Si dice “sin heartbeat”, avisar a soporte (servicio caído o no instalado).
4. Confirmar con soporte que el puerto y la IP del servidor son los que usa el RIS.

## Si persiste, indicar a soporte…

- Hora aproximada del envío desde el RIS
- Accession Number esperado
- Texto del badge “Probar listener”
- Puerto configurado

---

*Última actualización: 2026-09-02*
