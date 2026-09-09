# Cómo Subasignar Estudios a Cuentas Hijas

## Introducción
Esta guía explica cómo una **cuenta principal/padre** puede derivar (subasignar) estudios a sus **cuentas hijas**.

## Requisitos Previos

Para poder subasignar un estudio, se deben cumplir las siguientes condiciones:

1. **Tener un estudio asignado**: El estudio debe estar asignado a tu cuenta principal
2. **Tener cuentas hijas**: Debes tener al menos una cuenta hija asociada a tu cuenta
3. **Estar en estudios-manager.html**: La funcionalidad está disponible en la interfaz de gestión de estudios

## Pasos para Subasignar un Estudio

### 1. Identificar el Botón de Derivación

En la tabla de estudios, verás un botón verde con el icono `+` y el texto **"Derivar"** en cada estudio que tengas asignado (solo si tienes cuentas hijas).

```
[ Ver ] [ Derivar ] [ Antecedentes ]
```

### 2. Hacer Click en "Derivar"

Al hacer click en el botón "Derivar", se abrirá un modal titulado:
**"Derivar Estudio a Cuentas Hijas"**

### 3. Revisar la Información del Estudio

El modal mostrará:
- Nombre del paciente
- ID del paciente
- Modalidad del estudio

### 4. Seleccionar Cuentas Hijas

Verás una lista con todas tus cuentas hijas disponibles. Cada cuenta muestra:
- Nombre completo
- Email

Puedes seleccionar **una o más** cuentas hijas marcando las casillas correspondientes.

### 5. Confirmar la Derivación

Haz click en el botón **"Confirmar Derivación"** (verde) para completar el proceso.

## Resultado

Una vez confirmada la derivación:

1. ✅ **Mensaje de éxito**: Verás un mensaje confirmando a qué cuentas se derivó el estudio
2. 🔄 **Actualización automática**: La tabla se actualizará para mostrar las derivaciones
3. 📊 **Columna "Derivaciones"**: Verás información sobre las cuentas hijas a las que derivaste el estudio

## Visualización de Derivaciones

### Si derivaste a UNA cuenta hija:
```
👤 Juan Pérez
   01/01/2024 10:30
```

### Si derivaste a MÚLTIPLES cuentas hijas:
```
👥 3 derivaciones
   [ Ver detalles ]
```

Al hacer click en "Ver detalles", verás un modal con información completa de todas las derivaciones.

## Importante

- ⚠️ **No puedes subasignar estudios que no están asignados a ti**
- ⚠️ **Solo puedes derivar a cuentas que sean tus hijas directas**
- ⚠️ **El estudio seguirá visible en tu lista** incluso después de derivarlo
- ⚠️ **No puedes derivar el mismo estudio dos veces a la misma cuenta hija**

## Jerarquía y Visibilidad

### Cuenta Principal (Tú):
- ✅ Ves el estudio asignado a ti
- ✅ Ves las derivaciones que hiciste
- ✅ Puedes seguir derivando a más cuentas hijas

### Cuentas Hijas:
- ✅ Ven los estudios que les derivaste
- ❌ NO ven otros estudios que no les fueron derivados

### Cuentas Root/Admin:
- ✅ Ven TODAS las asignaciones
- ✅ Ven TODAS las derivaciones
- ✅ Tienen visibilidad completa del sistema

## Solución de Problemas

### No veo el botón "Derivar"
**Posibles causas:**
1. El estudio no está asignado a tu cuenta
2. No tienes cuentas hijas configuradas
3. No tienes los permisos necesarios

### Error: "No tienes cuentas hijas para derivar este estudio"
**Solución:** Contacta al administrador para que cree cuentas hijas asociadas a tu cuenta principal.

### Error: "Este estudio ya está derivado al usuario"
**Causa:** Ya derivaste este estudio a esa cuenta hija anteriormente.
**Solución:** Verifica las derivaciones existentes en la columna "Derivaciones".

## Soporte Técnico

Si tienes problemas o dudas sobre la derivación de estudios, contacta al administrador del sistema.

