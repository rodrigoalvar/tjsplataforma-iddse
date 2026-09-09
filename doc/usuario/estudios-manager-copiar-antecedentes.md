# Copiar antecedentes a otros estudios (mismo día)

Guía para usuarios de **Estudios Manager** (Gestión / Derivaciones).

---

## ¿Para qué sirve?

Un paciente puede tener **varios estudios el mismo día** (por ejemplo TC y RX). Los antecedentes se cargan por estudio; para no escribir lo mismo varias veces, el sistema permite **copiar** notas y archivos a los otros estudios del **mismo paciente** y la **misma fecha**.

No se ofrecen estudios de fechas anteriores.

---

## Cómo usarlo

### 1. Sugerencia al guardar

1. Abrí **Antecedentes** de un estudio.
2. Completá notas (y archivos si aplica).
3. Pulsá **Guardar Antecedentes**.
4. Si el paciente tiene otros estudios **en esa misma fecha** (en la lista que estás viendo), aparecerá un mensaje preguntando si querés copiarlos.
5. Si aceptás, se abre un selector para elegir destinos.

### 2. Botón en el modal

Si el paciente tiene **otros estudios en la misma fecha**, verás:

- Un texto de aviso: *“Este paciente tiene N estudios más en esta fecha…”*
- El botón **Copiar a otros del mismo día**:
  - **Visible pero desactivado** hasta que guardes (así sabés que hay otros estudios).
  - **Activo** después de **Guardar Antecedentes**.

Orden correcto:

1. Completar notas / archivos  
2. **Guardar Antecedentes** (el botón de copia se habilita)  
3. Copiar con el botón o aceptar la sugerencia al guardar

---

## Selector de estudios

En la lista verás:

| Columna | Significado |
|---------|-------------|
| Checkbox | Estudio destino |
| Modalidad / hora / descripción | Para identificar el estudio |
| Estado | **Sin antecedentes** o **Ya tiene antecedentes** |

Por defecto se marcan los que **aún no tienen** antecedentes. Podés marcar o desmarcar a mano, o usar “seleccionar todos”.

### Notas en el destino

- **Sobrescribir:** las notas del destino se reemplazan por las del origen.
- **Agregar al final:** si el destino ya tenía notas, se agregan las del origen debajo (separadas).

Los **archivos** siempre se **duplican** en el destino (no se borran los que ya tenía).

---

## Qué no hace esta función

- No copia a estudios de **otra fecha**.
- No busca estudios que no estén en la **lista actual** de Estudios Manager (si filtraste por fecha, solo ve esos).
- No reemplaza el modelo “un antecedente por estudio”: cada estudio sigue teniendo su propia copia.

---

## Si algo falla

Indicá a soporte:

- PatientID
- Fecha del estudio
- Estudio origen y destinos intentados

---

*Última actualización: 2026-09-02*
