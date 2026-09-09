# Sistema de Comandos de Voz Personalizables

## Descripción

El Portal de Estudios ahora incluye un sistema completo de comandos de voz personalizables que permite a los usuarios agregar, editar y eliminar comandos de dictado según sus necesidades específicas.

## Características Principales

### 🎤 Comandos de Voz Dinámicos
- **Comandos Predeterminados**: Incluye comandos básicos de puntuación, formato, unidades médicas y edición
- **Comandos Personalizados**: Permite agregar comandos específicos para cada usuario
- **Configuración Persistente**: Los comandos personalizados se guardan automáticamente en el navegador

### 🛠️ Gestor de Comandos
- **Interfaz Visual**: Gestor completo con interfaz web intuitiva
- **Categorización**: Organización por categorías (Puntuación, Formato, Médico, Personalizado)
- **Búsqueda**: Función de búsqueda para encontrar comandos rápidamente
- **Vista Previa**: Previsualización en tiempo real de los comandos

### 📁 Importar/Exportar
- **Exportar Configuración**: Descarga la configuración en formato JSON
- **Importar Configuración**: Carga configuraciones desde archivos JSON
- **Respaldo**: Fácil creación de respaldos de la configuración

## Cómo Usar

### Acceso al Gestor de Comandos

1. **Desde el Editor**:
   - Abre el editor de informes
   - En la sección "Dictado por Voz", haz clic en "Personalizar Comandos"

2. **Acceso Directo**:
   - Navega a: `http://localhost/PORTAL_ESTUDIOS/voice-commands-manager.html`

### Agregar Comandos Personalizados

1. En el gestor de comandos, completa el formulario:
   - **Comando de Voz**: El texto que dirás (ej: "punto final")
   - **Reemplazo**: El texto que se insertará (ej: ".")
   - **Categoría**: Selecciona la categoría apropiada

2. Haz clic en "Guardar Comando"

3. El comando estará disponible inmediatamente en el dictado

### Editar Comandos Existentes

1. En la lista de comandos, busca el comando que deseas editar
2. Haz clic en el botón de editar (📝)
3. Modifica los campos necesarios
4. Haz clic en "Actualizar Comando"

### Eliminar Comandos

1. En la lista de comandos, busca el comando que deseas eliminar
2. Haz clic en el botón de eliminar (🗑️)
3. Confirma la eliminación

**Nota**: Solo se pueden eliminar comandos personalizados, no los predeterminados.

## Comandos Predeterminados

### Puntuación Básica
- `"punto"` → .
- `"coma"` → ,
- `"dos puntos"` → :
- `"punto y coma"` → ;
- `"interrogación"` → ?
- `"exclamación"` → !
- `"comillas"` → "
- `"paréntesis abierto"` → (
- `"paréntesis cerrado"` → )

### Formato Especial
- `"punto aparte"` → .\n\n (nuevo párrafo)
- `"nueva línea"` → \n (salto de línea)
- `"párrafo nuevo"` → \n\n (doble salto)
- `"espacio"` → (espacio)
- `"tabulación"` → \t (tabulación)

### Unidades Médicas
- `"milímetros"` → mm
- `"centímetros"` → cm
- `"metros"` → m
- `"kilogramos"` → kg
- `"gramos"` → g
- `"miligramos"` → mg
- `"litros"` → L
- `"mililitros"` → ml
- `"grados celsius"` → °C
- `"por ciento"` → %
- `"más menos"` → ±

### Comandos de Edición
- `"mayúscula [texto]"` → Convierte texto a MAYÚSCULAS
- `"minúscula [texto]"` → Convierte texto a minúsculas
- `"borrar"` → Elimina el comando del texto
- `"deshacer"` → Ejecuta Ctrl+Z en el editor

## Variaciones de Comandos

El sistema reconoce variaciones naturales de los comandos:
- `"pon punto"` → .
- `"agrega coma"` → ,
- `"escribe dos puntos"` → :

## Gestión de Configuración

### Exportar Configuración
1. En el gestor de comandos, haz clic en "Exportar Configuración"
2. Se descargará un archivo `voice-commands-config.json`
3. Guarda este archivo como respaldo

### Importar Configuración
1. Haz clic en "Importar Configuración"
2. Selecciona un archivo JSON de configuración válido
3. Los comandos se cargarán automáticamente

### Restaurar Predeterminados
1. Haz clic en "Restaurar Predeterminados"
2. Confirma la acción
3. Se eliminarán todos los comandos personalizados

## Estructura Técnica

### Archivos del Sistema
- `assets/js/voice-commands-config.js` - Configuración y gestión de comandos
- `assets/js/voice-commands-manager.js` - Interfaz del gestor
- `voice-commands-manager.html` - Página del gestor
- `assets/js/speech.js` - Módulo de reconocimiento de voz (modificado)

### Almacenamiento
- Los comandos personalizados se guardan en `localStorage`
- Clave: `voiceCommandsConfig`
- Formato: JSON

### Eventos
- `voiceCommandsChanged` - Se dispara cuando cambian los comandos
- Permite actualización en tiempo real de la configuración

## Solución de Problemas

### Los comandos no funcionan
1. Verifica que el micrófono esté habilitado
2. Asegúrate de que el navegador soporte Web Speech API
3. Revisa la consola del navegador para errores

### Los comandos personalizados no se guardan
1. Verifica que el navegador permita localStorage
2. Comprueba que no estés en modo incógnito
3. Revisa el espacio disponible en localStorage

### Error al importar configuración
1. Verifica que el archivo sea un JSON válido
2. Asegúrate de que tenga la estructura correcta
3. Comprueba que el archivo no esté corrupto

## Consejos de Uso

1. **Comandos Claros**: Usa comandos fáciles de pronunciar y recordar
2. **Evita Conflictos**: No uses comandos que puedan confundirse con palabras comunes
3. **Prueba Regularmente**: Usa la función de prueba para verificar que los comandos funcionan
4. **Haz Respaldos**: Exporta tu configuración regularmente
5. **Organiza por Categorías**: Usa las categorías para mantener organizados tus comandos

## Soporte

Para soporte técnico o sugerencias de mejora, contacta al equipo de desarrollo del Portal de Estudios.