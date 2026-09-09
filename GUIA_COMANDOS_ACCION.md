# Guía de Comandos de Acción de Voz

## ¿Qué son los Comandos de Acción?

Los comandos de acción son comandos de voz que ejecutan funciones específicas en lugar de insertar texto. A diferencia de los comandos de texto que reemplazan palabras con símbolos o frases, los comandos de acción realizan acciones como guardar documentos, insertar plantillas, o ejecutar atajos de teclado.

## Cómo Acceder al Gestor de Comandos

1. Abre el editor de texto
2. Haz clic en el botón **"Gestor de Comandos"** en la barra de herramientas
3. Se abrirá el modal del gestor de comandos de voz

## Cómo Ver Comandos de Acción Existentes

1. En el modal del gestor, haz clic en la pestaña **"Acciones"** en los filtros de categoría
2. Verás todos los comandos de acción disponibles con:
   - Badge amarillo con icono de rayo que indica "Acción"
   - Descripción de lo que hace cada comando
   - Botones para editar y eliminar (solo comandos personalizados)

## Comandos de Acción Predefinidos

El sistema incluye estos comandos de acción por defecto:

- **"insertar modelo [nombre]"**: Inserta plantillas médicas predefinidas
- **"guardar documento"**: Guarda el documento actual (Ctrl+S)
- **"nuevo párrafo"**: Crea un nuevo párrafo
- **"seleccionar todo"**: Selecciona todo el texto (Ctrl+A)
- **"copiar texto"**: Copia el texto seleccionado (Ctrl+C)
- **"pegar texto"**: Pega el texto del portapapeles (Ctrl+V)

## Cómo Agregar un Nuevo Comando de Acción

1. En el modal del gestor, busca la sección **"Agregar Comando de Acción"**
2. Completa los campos:
   - **Comando de Voz**: La frase que dirás (ej: "abrir menú")
   - **Acción**: Selecciona de la lista desplegable la función a ejecutar
   - **Descripción**: Descripción opcional de lo que hace
   - **Patrón**: Solo para comandos avanzados con parámetros

3. Haz clic en **"Guardar Acción"**

### Ejemplo: Crear comando "abrir menú"
```
Comando de Voz: abrir menú
Acción: selectAll
Descripción: Abre el menú principal
```

## Cómo Editar un Comando de Acción

1. Ve a la categoría **"Acciones"** o **"Todos"**
2. Busca el comando que quieres editar
3. Haz clic en el botón **"Editar"** (icono de lápiz)
4. Modifica los campos necesarios
5. Haz clic en **"Actualizar Acción"**

## Cómo Eliminar un Comando de Acción

1. Busca el comando en la lista
2. Haz clic en el botón **"Eliminar"** (icono de papelera)
3. Confirma la eliminación en el diálogo

**Nota**: Solo puedes editar y eliminar comandos personalizados, no los predefinidos.

## Comandos con Parámetros

Algunos comandos pueden recibir parámetros usando patrones de expresión regular:

### Ejemplo: "insertar modelo [nombre]"
```
Comando: insertar modelo
Patrón: /^insertar modelo (.+)$/i
Acción: insertTemplate
```

Esto permite decir:
- "insertar modelo historia clínica"
- "insertar modelo receta médica"
- "insertar modelo nota evolución"

## Acciones Disponibles

- **insertTemplate**: Inserta plantillas predefinidas
- **saveDocument**: Guarda el documento (Ctrl+S)
- **newParagraph**: Crea nuevo párrafo
- **selectAll**: Selecciona todo (Ctrl+A)
- **copyText**: Copia texto (Ctrl+C)
- **pasteText**: Pega texto (Ctrl+V)

## Plantillas Médicas Disponibles

Cuando uses "insertar modelo [nombre]", puedes usar:

- **historia_clinica**: Plantilla de historia clínica
- **nota_evolucion**: Plantilla de nota de evolución
- **receta_medica**: Plantilla de receta médica
- **informe_radiologico**: Plantilla de informe radiológico
- **epicrisis**: Plantilla de epicrisis

### Ejemplo de uso:
```
"insertar modelo historia_clinica"
"insertar modelo nota_evolucion"
```

## Consejos y Buenas Prácticas

1. **Comandos claros**: Usa frases naturales y fáciles de recordar
2. **Descripciones útiles**: Agrega descripciones que expliquen claramente la función
3. **Evita duplicados**: No crees comandos que ya existen
4. **Prueba los comandos**: Usa la función de reconocimiento de voz para probar
5. **Organización**: Mantén una lista organizada de tus comandos personalizados

## Exportar e Importar Configuración

Puedes exportar tu configuración de comandos (incluyendo acciones) para:
- Hacer respaldo
- Compartir con otros usuarios
- Transferir entre dispositivos

Usa los botones **"Exportar"** e **"Importar"** en la sección de acciones rápidas.

## Solución de Problemas

### El comando no se ejecuta
- Verifica que el micrófono esté funcionando
- Asegúrate de pronunciar el comando exactamente como está configurado
- Revisa que la acción esté correctamente seleccionada

### Error al guardar comando
- Verifica que todos los campos requeridos estén completos
- Asegúrate de que el comando no exista ya
- Si usas patrones, verifica que la expresión regular sea válida

### Comando no aparece en la lista
- Verifica que estés en la categoría correcta ("Acciones" o "Todos")
- Usa la función de búsqueda para encontrar el comando
- Refresca la página si es necesario

---

**¡Importante!** Los comandos de acción se guardan localmente en tu navegador. Si limpias los datos del navegador, perderás los comandos personalizados. Recuerda hacer respaldos regulares usando la función de exportar.