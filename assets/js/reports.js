// Módulo de gestión de informes
const REPORTS_BASE_PREFIX = (() => {
  try {
    const scripts = document.getElementsByTagName('script');
    for (let i = 0; i < scripts.length; i++) {
      const src = scripts[i].getAttribute('src') || '';
      const marker = '/assets/js/reports.js';
      const pos = src.indexOf(marker);
      if (pos !== -1) {
        const prefix = src.substring(0, pos);
        return prefix || '';
      }
    }
  } catch (e) {}
  const path = (typeof window !== 'undefined' && window.location && typeof window.location.pathname === 'string') ? window.location.pathname : '';
  return path.includes('/PORTAL_ESTUDIOS') ? '/PORTAL_ESTUDIOS' : '';
})();
const ReportsModule = {
    // Estado del editor
    editorState: {
        currentTemplate: null,
        content: '',
        isDirty: false,
        currentReportId: null
    },

    // Inicializar el editor
    initEditor: function() {
        // Configurar traducciones completas en español
        tinymce.addI18n('es', {
            // Acciones básicas
            'Undo': 'Deshacer',
            'Redo': 'Rehacer',
            'Cut': 'Cortar',
            'Copy': 'Copiar',
            'Paste': 'Pegar',
            'Select all': 'Seleccionar todo',
            
            // Formato de texto
            'Bold': 'Negrita',
            'Italic': 'Cursiva',
            'Underline': 'Subrayado',
            'Strikethrough': 'Tachado',
            'Superscript': 'Superíndice',
            'Subscript': 'Subíndice',
            
            // Alineación
            'Align left': 'Alinear a la izquierda',
            'Align center': 'Centrar',
            'Align right': 'Alinear a la derecha',
            'Justify': 'Justificar',
            
            // Listas
            'Bullet list': 'Lista con viñetas',
            'Numbered list': 'Lista numerada',
            'Decrease indent': 'Disminuir sangría',
            'Increase indent': 'Aumentar sangría',
            
            // Enlaces e imágenes
            'Insert/edit link': 'Insertar/editar enlace',
            'Remove link': 'Quitar enlace',
            'Insert/edit image': 'Insertar/editar imagen',
            'Insert/edit media': 'Insertar/editar multimedia',
            
            // Tablas
            'Insert table': 'Insertar tabla',
            'Table properties': 'Propiedades de tabla',
            'Delete table': 'Eliminar tabla',
            'Insert row before': 'Insertar fila antes',
            'Insert row after': 'Insertar fila después',
            'Delete row': 'Eliminar fila',
            'Insert column before': 'Insertar columna antes',
            'Insert column after': 'Insertar columna después',
            'Delete column': 'Eliminar columna',
            
            // Código y herramientas
            'Source code': 'Código fuente',
            'View': 'Ver',
            'Edit': 'Editar',
            'Tools': 'Herramientas',
            'Insert': 'Insertar',
            
            // Formatos
            'Format': 'Formato',
            'Formats': 'Formatos',
            'Paragraph': 'Párrafo',
            'Heading 1': 'Encabezado 1',
            'Heading 2': 'Encabezado 2',
            'Heading 3': 'Encabezado 3',
            'Heading 4': 'Encabezado 4',
            'Heading 5': 'Encabezado 5',
            'Heading 6': 'Encabezado 6',
            'Preformatted': 'Preformateado',
            
            // Colores y estilos
            'Text color': 'Color del texto',
            'Background color': 'Color de fondo',
            'Font Family': 'Familia de fuente',
            'Font Sizes': 'Tamaños de fuente',
            
            // Diálogos comunes
            'OK': 'Aceptar',
            'Cancel': 'Cancelar',
            'Close': 'Cerrar',
            'Save': 'Guardar',
            'Apply': 'Aplicar',
            'Yes': 'Sí',
            'No': 'No',
            
            // Mensajes
             'Rich Text Area': 'Área de texto enriquecido',
             'Press ALT-F10 for toolbar. Press ALT-0 for help': 'Presiona ALT-F10 para la barra de herramientas. Presiona ALT-0 para ayuda',
             
             // Menús principales
             'File': 'Archivo',
             'Edit': 'Editar',
             'View': 'Ver',
             'Insert': 'Insertar',
             'Format': 'Formato',
             'Tools': 'Herramientas',
             'Table': 'Tabla',
             'Help': 'Ayuda',
             
             // Submenús de Archivo
             'New document': 'Nuevo documento',
             'Print': 'Imprimir',
             
             // Submenús de Editar
             'Find and replace': 'Buscar y reemplazar',
             'Find': 'Buscar',
             'Replace': 'Reemplazar',
             'Find next': 'Buscar siguiente',
             'Find previous': 'Buscar anterior',
             'Replace all': 'Reemplazar todo',
             
             // Submenús de Ver
             'Visual aids': 'Ayudas visuales',
             'Fullscreen': 'Pantalla completa',
             'Preview': 'Vista previa',
             
             // Submenús de Insertar
             'Date/time': 'Fecha/hora',
             'Special character': 'Carácter especial',
             'Special characters': 'Caracteres especiales',
             'Horizontal line': 'Línea horizontal',
             'Page break': 'Salto de página',
             'Nonbreaking space': 'Espacio sin salto',
             'Anchor': 'Ancla',
             
             // Submenús de Formato
             'Clear formatting': 'Limpiar formato',
             'Remove format': 'Quitar formato',
             'Font family': 'Familia de fuente',
             'Font size': 'Tamaño de fuente',
             'Line height': 'Altura de línea',
             'Blocks': 'Bloques',
             'Inline': 'En línea',
             'Styles': 'Estilos',
             
             // Submenús de Herramientas
             'Spellcheck': 'Corrector ortográfico',
             'Word count': 'Contador de palabras',
             'Character count': 'Contador de caracteres',
             
             // Menús contextuales
             'Cut': 'Cortar',
             'Copy': 'Copiar',
             'Paste': 'Pegar',
             'Link': 'Enlace',
             'Open link': 'Abrir enlace',
             'Edit link': 'Editar enlace',
             'Unlink': 'Quitar enlace',
             'Image': 'Imagen',
             'Edit image': 'Editar imagen',
             
             // Estados y acciones
             'Loading...': 'Cargando...',
             'Uploading...': 'Subiendo...',
             'Upload': 'Subir',
             'Browse': 'Examinar',
             'Alternative description': 'Descripción alternativa',
             'Accessibility': 'Accesibilidad',
             
             // Elementos de formulario
             'Width': 'Ancho',
             'Height': 'Alto',
             'Title': 'Título',
             'Description': 'Descripción',
             'URL': 'URL',
             'Target': 'Destino',
             'Text': 'Texto',
             'Caption': 'Leyenda',
             'Alignment': 'Alineación',
             'Style': 'Estilo',
             'Class': 'Clase',
             'Border': 'Borde',
              'Spacing': 'Espaciado',
              'Padding': 'Relleno',
              'Margin': 'Margen',
              
              // Opciones adicionales de menús
              'Font': 'Fuente',
              'Size': 'Tamaño',
              'Color': 'Color',
              'More...': 'Más...',
              'Less...': 'Menos...',
              'None': 'Ninguno',
              'Default': 'Predeterminado',
              'Custom': 'Personalizado',
              'Auto': 'Automático',
              
              // Opciones de formato
              'Normal': 'Normal',
              'Code': 'Código',
              'Quote': 'Cita',
              'Blockquote': 'Cita en bloque',
              'Pre': 'Preformateado',
              'Address': 'Dirección',
              
              // Opciones de lista
              'Default': 'Predeterminado',
              'Circle': 'Círculo',
              'Disc': 'Disco',
              'Square': 'Cuadrado',
              'Lower Alpha': 'Alfabético minúscula',
              'Lower Greek': 'Griego minúscula',
              'Lower Roman': 'Romano minúscula',
              'Upper Alpha': 'Alfabético mayúscula',
              'Upper Roman': 'Romano mayúscula',
              
              // Opciones de tabla
              'Row': 'Fila',
              'Column': 'Columna',
              'Cell': 'Celda',
              'Header': 'Encabezado',
              'Body': 'Cuerpo',
              'Footer': 'Pie',
              'Row group': 'Grupo de filas',
              'Column group': 'Grupo de columnas',
              'Cell type': 'Tipo de celda',
              'Cell properties': 'Propiedades de celda',
              'Row properties': 'Propiedades de fila',
              'Table properties': 'Propiedades de tabla',
              'Border width': 'Ancho del borde',
              'Border style': 'Estilo del borde',
              'Border color': 'Color del borde',
              'Row type': 'Tipo de fila',
              'Scope': 'Ámbito',
              'Alignment': 'Alineación',
              'H Align': 'Alineación horizontal',
              'V Align': 'Alineación vertical',
              
              // Opciones de imagen
              'Image description': 'Descripción de imagen',
              'Image list': 'Lista de imágenes',
              'Upload image': 'Subir imagen',
              'Constrain proportions': 'Mantener proporciones',
              'General': 'General',
              'Advanced': 'Avanzado',
              
              // Opciones de enlace
              'Link list': 'Lista de enlaces',
              'Link title': 'Título del enlace',
              'New window': 'Nueva ventana',
              'Same window': 'Misma ventana',
              'Parent window': 'Ventana padre',
              'Top window': 'Ventana superior',
              
              // Mensajes de estado
              'Loading': 'Cargando',
              'Saving': 'Guardando',
              'Saved': 'Guardado',
              'Error': 'Error',
              'Success': 'Éxito',
              'Warning': 'Advertencia',
              'Information': 'Información',
              
              // Botones y acciones
              'Browse for an image': 'Buscar una imagen',
              'Drop an image here': 'Arrastra una imagen aquí',
              'Upload': 'Subir',
              'Insert': 'Insertar',
              'Update': 'Actualizar',
              'Remove': 'Quitar',
              'Delete': 'Eliminar',
              'Clear': 'Limpiar',
              'Reset': 'Restablecer',
              'Restore': 'Restaurar',
              
              // Opciones específicas de menús que faltaban
            'Print': 'Imprimir',
            'Print...': 'Imprimir...',
            'Paste as text': 'Pegar como texto',
            'Image...': 'Imagen...'
          });
        
        // Inicializar TinyMCE
        tinymce.init({
            selector: '#reportEditor',
            language: 'es',
            plugins: 'lists table link image code autosave',
            toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code | restoredraft',
            height: 500,
            
            // Configuración de autosave
            autosave_ask_before_unload: false,
            autosave_interval: '10s',
            autosave_prefix: 'tinymce-autosave-{path}{query}-{id}-',
            autosave_restore_when_empty: true,
            autosave_retention: '30m',

            content_langs: [
                { title: 'Español', code: 'es' },
                { title: 'English', code: 'en' }
            ],
            setup: function(editor) {
                editor.on('Change', async function() {
                    ReportsModule.editorState.isDirty = true;
                    ReportsModule.editorState.content = editor.getContent();
                    // Guardar en persistencia personalizada también
                    if (window.PersistenceModule) {
                        await PersistenceModule.saveEditorState();
                    }
                });
                
                // Restaurar contenido desde persistencia al inicializar
                editor.on('init', function() {
                    if (window.PersistenceModule) {
                        setTimeout(() => {
                            PersistenceModule.restoreEditorState();
                        }, 500);
                    }
                });
            }
        });

        // Cargar plantillas predefinidas
        this.loadTemplates();
        
        // Agregar botones de acción
        this.updateActionButtons();
        
        // Resetear estado del editor para nuevo proceso
        this.editorState.currentReportId = null;
        this.editorState.isDirty = false;
        
        // Reactivar botón de guardar para nuevo proceso
        setTimeout(() => {
            this.enableSaveButton();
        }, 500);
        
        // Intentar cargar informe existente si hay studyInstanceUID en la URL
        const urlParams = this.getPatientDataFromURL();
        if (urlParams.studyInstanceUID) {
            setTimeout(() => {
                this.loadExistingReport(urlParams.studyInstanceUID);
            }, 1000); // Esperar a que TinyMCE esté completamente inicializado
        }
    },

    // Plantillas dinámicas (se actualizan desde TemplateManager)
    templates: {},

    // Actualizar plantillas desde TemplateManager
    updateTemplates: function(newTemplates) {
        this.templates = newTemplates;
    },

    // Cargar plantillas disponibles
    loadTemplates: function() {
        // Las plantillas ahora se cargan desde TemplateManager
        // Este método se mantiene para compatibilidad
    },

    // Obtener contenido de plantilla
    getTemplateContent: function(templateId) {
        // Usar plantillas dinámicas del TemplateManager
        if (this.templates[templateId]) {
            return this.templates[templateId].content;
        }
        
        // Fallback a plantillas predeterminadas si no se encuentra
        const defaultTemplates = {
            rx: `<h2>INFORME RADIOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`,
            ct: `<h2>INFORME TOMOGRÁFICO</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`,
            mri: `<h2>INFORME DE RESONANCIA MAGNÉTICA</h2>
<p><strong>Fecha:</strong> [FECHA]</p>
<p><strong>Paciente:</strong> [NOMBRE_PACIENTE]</p>
<p><strong>ID:</strong> [ID_PACIENTE]</p>
<hr>
<h3>TÉCNICA</h3>
<p>[DESCRIBIR_TÉCNICA]</p>
<h3>HALLAZGOS</h3>
<p>[DESCRIBIR_HALLAZGOS]</p>
<h3>IMPRESIÓN</h3>
<p>[CONCLUSIONES]</p>`
        };
        return defaultTemplates[templateId] || '';
    },

    // Cargar plantilla seleccionada
    loadTemplate: function(templateId) {
        let content = this.getTemplateContent(templateId);
        
        // Usar TagManager para procesar todos los tags dinámicamente
        if (window.TagManager) {
            const urlParams = this.getPatientDataFromURL();
            content = this.processTagsInContent(content, urlParams);
        } else {
            // Fallback al método anterior si TagManager no está disponible
            const patientData = this.getPatientDataFromURL();
            if (patientData.patientId && patientData.patientName) {
                const currentDate = new Date().toLocaleDateString('es-ES');
                content = content.replace(/\[FECHA\]/g, currentDate);
                content = content.replace(/\[NOMBRE_PACIENTE\]/g, patientData.patientName);
                content = content.replace(/\[ID_PACIENTE\]/g, patientData.patientId);
            }
        }
        
        tinymce.get('reportEditor').setContent(content);
        this.editorState.currentTemplate = templateId;
        this.editorState.isDirty = false;
    },

    // Función para obtener datos del paciente desde la URL
    getPatientDataFromURL: function() {
        const urlParams = new URLSearchParams(window.location.search);
        return {
            studyInstanceUID: urlParams.get('studyInstanceUID'),
            studyId: urlParams.get('studyId') || urlParams.get('orthancStudyId') || '',
            patientID: urlParams.get('patientID') || urlParams.get('patientId') || '',
            patientId: urlParams.get('patientId') || '',
            patientName: urlParams.get('patientName') || '',
            modality: urlParams.get('modality') || '',
            studyDate: urlParams.get('studyDate') || urlParams.get('date') || urlParams.get('study_date') || '',
            studyDescription: urlParams.get('studyDescription') || '',
            // Agregar parámetros adicionales para compatibilidad con TagManager
            id: urlParams.get('patientId') || urlParams.get('id') || '',
            nombre: urlParams.get('patientName') || urlParams.get('nombre') || '',
            edad: urlParams.get('edad') || '',
            sexo: urlParams.get('sexo') || '',
            medico: urlParams.get('medico') || ''
        };
    },
    
    // Procesar todos los tags en el contenido usando TagManager
    processTagsInContent: function(content, urlParams) {
        if (!window.TagManager) {
            return content;
        }
        
        // Buscar todos los tags en el formato [TAG_NAME]
        const tagRegex = /\[([A-Z_]+)\]/g;
        let processedContent = content;
        let match;
        
        while ((match = tagRegex.exec(content)) !== null) {
            const tagName = match[1];
            const tagValue = window.TagManager.getTagValue(tagName, urlParams);
            
            // Reemplazar todas las ocurrencias de este tag
            const tagPattern = new RegExp(`\\[${tagName}\\]`, 'g');
            processedContent = processedContent.replace(tagPattern, tagValue);
        }
        
        return processedContent;
    },

    // Utilidad para obtener cookies (fallback de token)
    getCookie: function(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    },

    // Guardar informe
    saveReport: async function() {
        console.log('=== INICIO saveReport ===>');
        
        try {
            // Verificar que TinyMCE esté inicializado
            const editor = tinymce.get('reportEditor');
            if (!editor) {
                console.error('Error: TinyMCE no está inicializado');
                this.showMessage('Error: El editor no está listo. Por favor, espera un momento e intenta nuevamente.', 'error');
                return;
            }
            
            // Verificar que el editor esté completamente cargado
            if (!editor.initialized) {
                console.error('Error: TinyMCE no está completamente inicializado');
                this.showMessage('Error: El editor aún se está cargando. Por favor, espera un momento e intenta nuevamente.', 'error');
                return;
            }
            
            // Mostrar mensaje inicial
            this.showMessage('Guardando informe...', 'info');
            console.log('Mensaje inicial mostrado');
            
            // Obtener contenido del editor
            const content = editor.getContent();
            console.log('Contenido obtenido:', content.length, 'caracteres');
            console.log('Contenido completo:', content);
            
            // Validar que haya contenido (aunque sea mínimo)
            if (!content || content.trim().length === 0) {
                console.warn('Advertencia: El editor está vacío');
                // Mostrar modal Bootstrap profesional para confirmar guardado vacío
                const shouldContinue = await this.showEmptyReportConfirmModal();
                if (!shouldContinue) {
                    console.log('Guardado cancelado por el usuario');
                    return;
                }
            }
            
            // Obtener datos del estudio desde la URL
            const urlParams = this.getPatientDataFromURL();
            console.log('Parámetros URL:', urlParams);
            
            if (!urlParams.studyInstanceUID) {
                console.error('Error: No se encontró studyInstanceUID');
                this.showMessage('Error: No se encontró ID del estudio', 'error');
                return;
            }
            
            // Obtener token de sesión (localStorage o cookie)
            const sessionToken = localStorage.getItem('sessionToken') || this.getCookie('session_token');
            console.log('Token de sesión:', sessionToken ? 'Encontrado' : 'NO ENCONTRADO');
            
            if (!sessionToken) {
                console.error('Error: No hay token de sesión');
                this.showMessage('Error: Sesión no válida', 'error');
                return;
            }
            
            // Preparar datos del informe
            const reportData = {
                estudio_id: urlParams.studyInstanceUID,
                study_instance_uid: urlParams.studyInstanceUID,
                study_id: urlParams.studyId,
                patient_id: urlParams.patientID || urlParams.patientId,
                patient_name: urlParams.patientName,
                modality: urlParams.modality,
                study_description: urlParams.studyDescription,
                contenido_html: content || '', // Asegurar que siempre sea un string
                titulo: this.generateReportTitle(urlParams),
                estado: 'borrador',
                // Incluir token en el cuerpo para compatibilidad con servidores que no exponen HTTP_AUTHORIZATION.
                session_token: sessionToken
            };
            
            console.log('Datos del informe preparados:', reportData);
            
            // Si hay un informe existente, incluir el ID para actualización
            if (this.editorState.currentReportId) {
                reportData.informe_id = this.editorState.currentReportId;
                reportData.id = this.editorState.currentReportId;
                console.log('Editando informe existente ID:', this.editorState.currentReportId);
            } else {
                console.log('Creando nuevo informe');
            }

            // Capturar si era CREATE antes de mutar editorState al recibir respuesta.
            // Sólo se notifica al dashboard cuando es create (el conteo cambia).
            const wasCreate = !this.editorState.currentReportId;

            console.log('Enviando petición a:', `${REPORTS_BASE_PREFIX}api/informes/save.php`);
             
             // Enviar al servidor
             const response = await fetch(`../api/informes/save.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + sessionToken
                },
                body: JSON.stringify(reportData)
            });
            
            console.log('Respuesta HTTP status:', response.status);
            
            if (!response.ok) {
                const errorText = await response.text();
                let errorData;
                try {
                    errorData = JSON.parse(errorText);
                } catch (e) {
                    errorData = { message: errorText };
                }
                console.error('Error al guardar informe (HTTP):', response.status, errorData);
                
                // Mostrar mensaje de error más descriptivo
                const errorMessage = errorData.message || `Error al guardar (HTTP ${response.status})`;
                this.showMessage(errorMessage, 'error');
                return;
            }
            
            const data = await response.json();
            console.log('Resultado del servidor:', data);
            
            if (data.success) {
                console.log('Guardado exitoso');
                
                this.editorState.isDirty = false;
                
                // Actualizar currentReportId si es un nuevo informe
                if (data.data && data.data.informe_id && !this.editorState.currentReportId) {
                    this.editorState.currentReportId = data.data.informe_id;
                    console.log('Nuevo informe_id asignado:', data.data.informe_id);
                }
                
                // Guardar audios automáticamente después de guardar el informe
                console.log('Guardando audios pendientes...');
                await this.saveAllPendingAudios();
                
                // Migrar audios temporales a permanentes
                console.log('Migrando audios temporales a permanentes...');
                const migrationResult = await this.migrateTempAudiosSimple(
                    urlParams.studyInstanceUID,
                    urlParams.studyId
                );
                console.log('Resultado de migración:', migrationResult);
                
                // Subir archivos de audio adjuntos
                if (window.AudioAttachmentsManager) {
                    // Obtener informe_id del resultado o del estado
                    const informeId = data.data?.informe_id || data.data?.id || this.editorState.currentReportId;
                    if (informeId) {
                        console.log('Subiendo archivos de audio adjuntos para informe ID:', informeId);
                        try {
                            const uploadResult = await window.AudioAttachmentsManager.uploadAttachments(informeId);
                            console.log('Resultado de subida de adjuntos:', uploadResult);
                            if (uploadResult.success) {
                                console.log('✅ Archivos adjuntos subidos exitosamente:', uploadResult.message);
                            } else if (uploadResult.message !== 'No hay archivos pendientes') {
                                console.warn('⚠️ Algunos archivos adjuntos no se pudieron subir:', uploadResult.message);
                                // Mostrar advertencia al usuario si hay archivos que fallaron
                                if (uploadResult.results && uploadResult.results.some(r => !r.success)) {
                                    const failedFiles = uploadResult.results
                                        .filter(r => !r.success)
                                        .map(r => `${r.file}${r.error ? ` (${r.error})` : ''}`)
                                        .join(', ');
                                    this.showMessage(`Algunos archivos adjuntos no se pudieron subir: ${failedFiles}`, 'warning');
                                }
                            }
                        } catch (uploadError) {
                            console.error('Error subiendo archivos adjuntos:', uploadError);
                            // No bloquear el guardado del informe si falla la subida de adjuntos
                            this.showMessage('El informe se guardó correctamente, pero hubo un error al subir algunos archivos adjuntos. Puedes intentar subirlos manualmente.', 'warning');
                        }
                    } else {
                        console.warn('⚠️ No se pudo obtener informe_id para subir archivos adjuntos');
                    }
                }
                
                // Limpiar caché de persistencia del informe guardado
                console.log('Limpiando caché de persistencia del informe guardado...');
                this.clearPersistenceCache();
                
                // Mostrar confirmación visual con popup
                console.log('Mostrando modal de éxito');
                this.showSuccessModal('Informe guardado exitosamente', 'El informe y sus audios han sido guardados correctamente y están disponibles en Gestión de Informes.');
                
                // Desactivar botón de guardar
                console.log('Deshabilitando botón de guardar');
                this.disableSaveButton();

                // Notificar al dashboard que se finalizó/creó un informe.
                // Sólo dispatch en CREATE: las ediciones no alteran total_informes.
                // El storage event llega a iframes hermanos del mismo origen (dashboard
                // si sigue vivo). Si no hay nadie escuchando ahora, la entrada en
                // localStorage queda persistida y la guarda en el init() del dashboard
                // la detecta cuando el usuario vuelve, invalidando el caché.
                if (wasCreate) {
                    try {
                        const informeId =
                            (data.data && (data.data.informe_id || data.data.id)) ||
                            this.editorState.currentReportId || null;
                        const detail = {
                            informe_id: informeId,
                            study_id: urlParams.studyId || '',
                            study_instance_uid: urlParams.studyInstanceUID || '',
                            orthanc_id: urlParams.studyId || urlParams.studyInstanceUID || '',
                            action: 'create',
                            source: 'editor.html',
                            ts: Date.now()
                        };
                        try { window.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                        if (window.parent && window.parent !== window) {
                            try { window.parent.dispatchEvent(new CustomEvent('informeFinalizado', { detail })); } catch (e) {}
                            try { window.parent.postMessage({ type: 'informeFinalizado', detail }, '*'); } catch (e) {}
                        }
                        try { localStorage.setItem('informe_finalizado_event', JSON.stringify(detail)); } catch (e) {}
                        console.log('📣 informeFinalizado notificado al dashboard:', detail);
                    } catch (e) {
                        console.warn('No se pudo notificar informeFinalizado:', e);
                    }
                }

                console.log('=== FIN saveReport EXITOSO ===>');
                
            } else {
                console.error('Error al guardar informe (API):', data);
                this.showMessage('Error al guardar informe: ' + (data.error || 'Error desconocido'), 'error');
            }
        } catch (error) {
            console.error('=== ERROR en saveReport ===>', error);
            this.showMessage('Error al enviar informe: ' + error.message, 'error');
        }
    },

    // Generar título del informe
    generateReportTitle: function(urlParams) {
        const modality = urlParams.modality || 'Estudio';
        const patientName = urlParams.patientName || 'Paciente';
        const date = new Date().toLocaleDateString('es-ES');
        return `${modality} - ${patientName} - ${date}`;
    },
    
    // Mostrar mensajes de estado
    showMessage: function(message, type = 'info') {
        const statusDiv = document.getElementById('statusMessage');
        if (statusDiv) {
            statusDiv.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} mt-3`;
            statusDiv.textContent = message;
            statusDiv.style.display = 'block';
            
            // Auto-ocultar después de 5 segundos
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }
    },
    
    // Actualizar botones de acción
    updateActionButtons: function() {
        // Agregar botón para guardar audio si no existe
        const actionButtons = document.querySelector('.d-flex.flex-wrap.gap-3.justify-content-center');
        if (actionButtons && !document.getElementById('saveAudioBtn')) {
            const saveAudioBtn = document.createElement('button');
            saveAudioBtn.id = 'saveAudioBtn';
            saveAudioBtn.className = 'btn btn-primary btn-lg';
            saveAudioBtn.innerHTML = '<i class="fas fa-microphone me-2"></i>Guardar Audio';
            saveAudioBtn.onclick = () => this.saveCurrentAudio();
            
            // Insertar después del botón de guardar informe
            const saveReportBtn = actionButtons.querySelector('button[onclick="ReportsModule.saveReport()"]');
            if (saveReportBtn) {
                saveReportBtn.parentNode.insertBefore(saveAudioBtn, saveReportBtn.nextSibling);
            }
        }
    },
    
    // Guardar audio actual
    saveCurrentAudio: function() {
        if (!this.editorState.currentReportId) {
            this.showMessage('Primero debe guardar el informe', 'error');
            return;
        }
        
        // Intentar obtener audio de ambas secciones
        let audioBlob = null;
        let audioType = 'normal';
        let syncData = null;
        
        // Verificar grabación normal
        if (window.AudioModule && window.AudioModule.recordingState && window.AudioModule.recordingState.currentAudioBlob) {
            audioBlob = window.AudioModule.recordingState.currentAudioBlob;
            audioType = 'normal';
        }
        // Verificar grabación sincronizada
        else if (window.syncEditorModule && window.syncEditorModule.currentAudioBlob) {
            audioBlob = window.syncEditorModule.currentAudioBlob;
            audioType = 'sincronizada';
            syncData = window.syncEditorModule.syncData || [];
        }
        
        if (!audioBlob) {
            this.showMessage('No hay audio grabado para guardar', 'error');
            return;
        }
        
        this.uploadAudio(audioBlob, audioType, syncData);
    },
    
    // Subir audio al servidor
    uploadAudio: async function(audioBlob, recordingType, syncData = null) {
        try {
            if (!this.editorState.currentReportId) {
                this.showMessage('Debe guardar el informe antes de subir audio', 'error');
                return;
            }
            
            const sessionToken = localStorage.getItem('sessionToken') || this.getCookie('session_token');
            if (!sessionToken) {
                this.showMessage('Error: Sesión no válida', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('audio', audioBlob, `audio_${Date.now()}.webm`);
            formData.append('informe_id', this.editorState.currentReportId);
            formData.append('tipo_grabacion', recordingType);
            // Agregar token también en el cuerpo por compatibilidad
            formData.append('session_token', sessionToken);
            
            if (syncData) {
                formData.append('datos_sincronizacion', JSON.stringify(syncData));
            }
            
            this.showMessage('Subiendo audio...', 'info');
            
            const response = await fetch(`../api/audios/upload.php`, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + sessionToken
                },
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.showMessage('Audio guardado exitosamente', 'success');
                this.clearCurrentAudio();
            } else {
                this.showMessage(data.message || 'Error al guardar el audio', 'error');
            }
        } catch (error) {
            console.error('Error al subir audio:', error);
            this.showMessage('Error al subir el audio', 'error');
        }
    },
    
    // Limpiar audio actual después de guardar
    clearCurrentAudio: function() {
        // Limpiar audio normal
        if (window.AudioModule && typeof window.AudioModule.clearCurrentAudio === 'function') {
            window.AudioModule.clearCurrentAudio();
        }
        
        // Limpiar audio sincronizado
        if (window.syncEditorModule && typeof window.syncEditorModule.clearCurrentAudio === 'function') {
            window.syncEditorModule.clearCurrentAudio();
        }
        
        console.log('Audio limpiado después de guardar');
    },
    
    // Cargar informe existente
    loadExistingReport: function(studyId) {
        const sessionToken = localStorage.getItem('sessionToken');
        if (!sessionToken) {
            return;
        }
        
        fetch(`${REPORTS_BASE_PREFIX}/api/informes/get.php?estudio_id=${encodeURIComponent(studyId)}&incluir_audios=true`, {
            headers: {
                'Authorization': 'Bearer ' + sessionToken
            }
        })
        .then(async response => {
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error al cargar informe (HTTP):', response.status, errorText);
                return Promise.reject(new Error(`HTTP ${response.status}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.data.informes.length > 0) {
                const informe = data.data.informes[0];
                
                // Actualizar estado del editor con el ID del informe
                this.editorState.currentReportId = informe.id;
                
                // Cargar contenido en el editor
                if (tinymce.get('reportEditor')) {
                    tinymce.get('reportEditor').setContent(informe.contenido_html || '');
                }
                
                // Actualizar estado del editor
                this.editorState.content = informe.contenido_html || '';
                this.editorState.isDirty = false;
                
                this.updateActionButtons();
                this.showMessage(`Informe cargado: ${informe.titulo}`, 'success');
                
                // Si hay audios asociados, mostrar información
                if (informe.audios && informe.audios.length > 0) {
                    console.log('Audios asociados:', informe.audios);
                }
            }
        })
        .catch(error => {
            console.error('Error al cargar informe:', error);
            this.showMessage('Error al cargar informe existente', 'error');
        });
    },
    
    // Exportar a PDF
    exportToPDF: function() {
        if (typeof PDFModule !== 'undefined' && PDFModule.generateFromEditor) {
            PDFModule.generateFromEditor();
        }
    },

    // Guardar todos los audios pendientes automáticamente
    saveAllPendingAudios: async function() {
        let audiosSaved = 0;
        
        // Verificar y guardar audio regular
        if (window.AudioModule && window.AudioModule.recordingState && window.AudioModule.recordingState.currentAudioBlob) {
            try {
                await this.uploadAudio(window.AudioModule.recordingState.currentAudioBlob, 'regular');
                audiosSaved++;
            } catch (error) {
                console.error('Error al guardar audio regular:', error);
            }
        }
        
        // Verificar y guardar audio sincronizado
        if (window.syncEditorModule && window.syncEditorModule.currentAudioBlob) {
            try {
                const syncData = window.syncEditorModule.syncData || [];
                await this.uploadAudio(window.syncEditorModule.currentAudioBlob, 'sync', syncData);
                audiosSaved++;
            } catch (error) {
                console.error('Error al guardar audio sincronizado:', error);
            }
        }
        
        console.log(`Se guardaron ${audiosSaved} audios automáticamente`);
        return audiosSaved;
    },

    // Mostrar modal de confirmación exitosa
    showSuccessModal: function(title, message) {
        // Crear modal dinámicamente
        const modalHtml = `
            <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-success">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title" id="successModalLabel">
                                <i class="fas fa-check-circle me-2"></i>${title}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center">
                                <i class="fas fa-check-circle text-success" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                <p class="mb-0">${message}</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-success" data-bs-dismiss="modal">Entendido</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente si existe
        const existingModal = document.getElementById('successModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Agregar modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('successModal'));
        modal.show();
        
        // Limpiar modal del DOM después de cerrarlo
        document.getElementById('successModal').addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
    },

    // Mostrar modal de confirmación para informe vacío
    showEmptyReportConfirmModal: function() {
        return new Promise((resolve) => {
            // Crear modal dinámicamente
            const modalHtml = `
                <div class="modal fade" id="emptyReportModal" tabindex="-1" aria-labelledby="emptyReportModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-warning">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title" id="emptyReportModalLabel">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Informe Vacío
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-file-alt text-warning" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                    <p class="mb-2"><strong>El informe está vacío</strong></p>
                                    <p class="text-muted mb-0">
                                        No hay contenido de texto en el editor. Puedes guardar el informe vacío si tienes archivos de audio adjuntos o si deseas completarlo más tarde.
                                    </p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="window._emptyReportModalResolve(false)">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </button>
                                <button type="button" class="btn btn-warning" data-bs-dismiss="modal" onclick="window._emptyReportModalResolve(true)">
                                    <i class="fas fa-save me-2"></i>Guardar de Todas Formas
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remover modal existente si existe
            const existingModal = document.getElementById('emptyReportModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Agregar modal al DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Crear función de resolución global temporal
            window._emptyReportModalResolve = (value) => {
                resolve(value);
                delete window._emptyReportModalResolve;
            };
            
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('emptyReportModal'));
            modal.show();
            
            // Limpiar modal del DOM después de cerrarlo
            document.getElementById('emptyReportModal').addEventListener('hidden.bs.modal', function() {
                if (window._emptyReportModalResolve) {
                    window._emptyReportModalResolve(false);
                    delete window._emptyReportModalResolve;
                }
                this.remove();
            });
        });
    },

    // Desactivar botón de guardar
    disableSaveButton: function() {
        const saveButtons = document.querySelectorAll('button[onclick*="saveReport"]');
        saveButtons.forEach(button => {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-check me-2"></i>Informe Guardado';
            button.classList.remove('btn-success');
            button.classList.add('btn-secondary');
        });
    },

    // Reactivar botón de guardar (llamar cuando se inicie nuevo proceso)
    enableSaveButton: function() {
        const saveButtons = document.querySelectorAll('button[onclick*="saveReport"]');
        saveButtons.forEach(button => {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Informe';
            button.classList.remove('btn-secondary');
            button.classList.add('btn-success');
        });
    },

    // Migrar audios temporales a permanentes (función independiente)
    migrateTempAudiosToPermanent: async function(informeId, estudioId) {
        try {
            console.log('=== INICIO MIGRACIÓN DE AUDIOS DESDE REPORTSMODULE ===');
            console.log('Parámetros:', { informeId, estudioId });
            
            // Obtener token de sesión
            const sessionToken = localStorage.getItem('sessionToken') || this.getCookie('session_token');
            if (!sessionToken) {
                console.log('No hay token de sesión, saltando migración');
                return { success: true, migrated: 0 };
            }
            
            // Obtener audios temporales directamente desde la API
            const tempAudios = await this.getTempAudiosFromAPI();
            console.log('Audios temporales encontrados:', tempAudios.length);
            
            if (tempAudios.length === 0) {
                console.log('No hay audios temporales para migrar');
                return { success: true, migrated: 0 };
            }
            
            let migratedCount = 0;
            
            // Migrar cada audio temporal
            for (const tempAudio of tempAudios) {
                try {
                    console.log(`Migrando audio: ${tempAudio.fileName}`);
                    
                    // Descargar el archivo temporal
                    const response = await fetch(tempAudio.url);
                    const audioBlob = await response.blob();
                    
                    // Crear FormData para subir
                    const formData = new FormData();
                    formData.append('audio', audioBlob, tempAudio.fileName);
                    formData.append('informe_id', informeId);
                    formData.append('tipo_grabacion', tempAudio.audioType === 'sync' ? 'sincronizada' : 'simple');
                    formData.append('session_token', sessionToken);
                    
                    // Subir a la API permanente
                    const uploadResponse = await fetch('../api/audios/upload.php', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + sessionToken
                        },
                        body: formData
                    });
                    
                    if (uploadResponse.ok) {
                        const result = await uploadResponse.json();
                        if (result.success) {
                            migratedCount++;
                            console.log(`✅ Audio migrado: ${tempAudio.fileName} -> ${result.data.nombre_archivo}`);
                        } else {
                            console.error(`❌ Error migrando audio ${tempAudio.fileName}:`, result.message);
                        }
                    } else {
                        const errorText = await uploadResponse.text();
                        console.error(`❌ Error HTTP migrando audio ${tempAudio.fileName}:`, uploadResponse.status, errorText);
                    }
                    
                } catch (error) {
                    console.error(`❌ Error migrando audio ${tempAudio.fileName}:`, error);
                }
            }
            
            // Limpiar audios temporales después de migrar exitosamente
            if (migratedCount > 0) {
                console.log('Limpiando audios temporales...');
                await this.clearTempAudios();
                console.log(`✅ Migración completada: ${migratedCount} audios migrados`);
            } else {
                console.log('⚠️ No se migró ningún audio');
            }
            
            console.log('=== FIN MIGRACIÓN DE AUDIOS DESDE REPORTSMODULE ===');
            return { success: true, migrated: migratedCount };
            
        } catch (error) {
            console.error('❌ Error en migración de audios:', error);
            return { success: false, error: error.message, migrated: 0 };
        }
    },

    // Obtener audios temporales desde la API
    getTempAudiosFromAPI: async function() {
        try {
            // Obtener información del estudio desde la URL
            const urlParams = this.getPatientDataFromURL();
            if (!urlParams.studyInstanceUID) {
                console.log('No hay studyInstanceUID en la URL');
                return [];
            }
            
            // Extraer studyId y patientId del studyInstanceUID (asumiendo formato específico)
            const studyId = urlParams.studyInstanceUID;
            const patientId = urlParams.patientId || 'unknown';
            
            const url = `../components/temp-audio.php?studyId=${encodeURIComponent(studyId)}&patientId=${encodeURIComponent(patientId)}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                return result.data || [];
            } else {
                console.error('Error obteniendo audios temporales:', result.message);
                return [];
            }
        } catch (error) {
            console.error('Error en getTempAudiosFromAPI:', error);
            return [];
        }
    },

    // Limpiar audios temporales
    clearTempAudios: async function() {
        try {
            const urlParams = this.getPatientDataFromURL();
            if (!urlParams.studyInstanceUID) {
                return;
            }
            
            const studyId = urlParams.studyInstanceUID;
            const patientId = urlParams.patientId || 'unknown';
            
            const url = `../components/temp-audio.php?studyId=${encodeURIComponent(studyId)}&patientId=${encodeURIComponent(patientId)}`;
            const response = await fetch(url, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            if (result.success) {
                console.log('Audios temporales eliminados');
            } else {
                console.error('Error eliminando audios temporales:', result.message);
            }
        } catch (error) {
            console.error('Error en clearTempAudios:', error);
        }
    },

    // Migración simple de audios temporales
    migrateTempAudiosSimple: async function(studyInstanceUID, studyId) {
        try {
            console.log('=== INICIO MIGRACIÓN SIMPLE DE AUDIOS ===');
            console.log('Parámetros:', { studyInstanceUID, studyId });
            
            if (!studyInstanceUID && !studyId) {
                console.log('No hay studyInstanceUID o studyId, saltando migración');
                return { success: true, migrated: 0 };
            }
            
            // Obtener token de sesión
            const sessionToken = localStorage.getItem('sessionToken') || this.getCookie('session_token');
            if (!sessionToken) {
                console.log('No hay token de sesión, saltando migración');
                return { success: true, migrated: 0 };
            }
            
            // Llamar a la API de migración
            const response = await fetch('../api/audios/migrate-temp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + sessionToken
                },
                body: JSON.stringify({
                    studyInstanceUID: studyInstanceUID,
                    studyId: studyId,
                    session_token: sessionToken
                })
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error HTTP en migración:', response.status, errorText);
                return { success: false, error: `HTTP ${response.status}` };
            }
            
            const result = await response.json();
            console.log('Resultado de migración:', result);
            
            if (result.success) {
                console.log(`✅ Migración exitosa: ${result.data.migrated_count} audios migrados`);
                return { success: true, migrated: result.data.migrated_count };
            } else {
                console.error('❌ Error en migración:', result.message);
                return { success: false, error: result.message };
            }
            
        } catch (error) {
            console.error('❌ Error en migración simple:', error);
            return { success: false, error: error.message };
        }
    },
    
    /**
     * Limpiar caché de persistencia del informe guardado
     * Limpia: localStorage, sessionStorage, estudioURL, y avisos de cambios sin guardar
     * IMPORTANTE: Desactiva auto-save temporalmente para evitar que se regenere el caché
     */
    clearPersistenceCache: function() {
        try {
            console.log('🧹 Iniciando limpieza de caché de persistencia...');
            
            // DESACTIVAR auto-save temporalmente para evitar que regenere el caché
            let originalAutoSaveInterval = null;
            if (window.PersistenceModule && window.PersistenceModule.autoSaveInterval) {
                console.log('⏸️ Desactivando auto-save temporalmente...');
                originalAutoSaveInterval = window.PersistenceModule.autoSaveInterval;
                clearInterval(window.PersistenceModule.autoSaveInterval);
                window.PersistenceModule.autoSaveInterval = null;
                
                // También limpiar timeout de guardado por cambios
                if (window.PersistenceModule.saveTimeout) {
                    clearTimeout(window.PersistenceModule.saveTimeout);
                    window.PersistenceModule.saveTimeout = null;
                }
            }
            
            const urlParams = this.getPatientDataFromURL();
            
            // 1. Limpiar caché específico del informe guardado PRIMERO (antes de limpiar estudioURL)
            // Esto asegura que hasPersistedContent() devuelva false
            // IMPORTANTE: hasPersistedContent() usa studyId, no studyInstanceUID
            const studyId = urlParams.studyId || urlParams.studyInstanceUID;
            const patientId = urlParams.patientId || urlParams.patientID;
            
            if (studyId && patientId) {
                // Limpiar usando studyId (el que usa hasPersistedContent)
                const storageKey = `editor_persistence_${studyId}_${patientId}`;
                const sessionKey = `editor_session_${studyId}_${patientId}`;
                
                // También limpiar usando studyInstanceUID por si acaso hay otra clave
                const storageKeyByUID = urlParams.studyInstanceUID && urlParams.studyInstanceUID !== studyId ? 
                    `editor_persistence_${urlParams.studyInstanceUID}_${patientId}` : null;
                const sessionKeyByUID = urlParams.studyInstanceUID && urlParams.studyInstanceUID !== studyId ? 
                    `editor_session_${urlParams.studyInstanceUID}_${patientId}` : null;
                
                console.log('Limpiando claves específicas del informe...', {
                    storageKey,
                    sessionKey,
                    storageKeyByUID,
                    sessionKeyByUID
                });
                
                // Eliminar claves principales (usando studyId)
                localStorage.removeItem(storageKey);
                localStorage.removeItem(sessionKey);
                sessionStorage.removeItem(storageKey);
                sessionStorage.removeItem(sessionKey);
                
                // Eliminar claves alternativas si existen
                if (storageKeyByUID) {
                    localStorage.removeItem(storageKeyByUID);
                    sessionStorage.removeItem(storageKeyByUID);
                }
                if (sessionKeyByUID) {
                    localStorage.removeItem(sessionKeyByUID);
                    sessionStorage.removeItem(sessionKeyByUID);
                }
                
                // Verificar que realmente se eliminó
                const stillExists = localStorage.getItem(storageKey) || localStorage.getItem(sessionKey);
                if (stillExists) {
                    console.warn('⚠️ Algunas claves aún existen después de eliminarlas, forzando eliminación...');
                    localStorage.removeItem(storageKey);
                    localStorage.removeItem(sessionKey);
                    sessionStorage.removeItem(storageKey);
                    sessionStorage.removeItem(sessionKey);
                }
                
                console.log('✅ Caché específico del informe eliminado');
            }
            
            // 2. Limpiar persistencia del editor usando PersistenceModule
            if (window.PersistenceModule && typeof window.PersistenceModule.clearAllPersistence === 'function') {
                console.log('Limpiando persistencia del editor...');
                window.PersistenceModule.clearAllPersistence();
            }
            
            // 3. Limpiar flag de cambios sin guardar en PersistenceModule
            if (window.PersistenceModule) {
                window.PersistenceModule.hasUnsavedChanges = false;
            }
            
            // 4. Marcar TinyMCE como "limpio" si está disponible
            if (window.tinymce && tinymce.get('reportEditor')) {
                const editor = tinymce.get('reportEditor');
                if (editor && typeof editor.setDirty === 'function') {
                    editor.setDirty(false);
                    console.log('TinyMCE marcado como limpio (sin cambios sin guardar)');
                }
            }
            
            // 5. Limpiar estado interno de cambios sin guardar
            this.editorState.isDirty = false;
            this.editorState.currentReportId = null;
            
            // 6. Verificar ANTES de limpiar estudioURL si hay contenido persistido
            // (hasPersistedContent requiere estudio activo)
            let hasRemainingContent = false;
            if (window.urlStudyManager && window.urlStudyManager.hasActiveStudy()) {
                hasRemainingContent = window.urlStudyManager.hasPersistedContent();
                if (hasRemainingContent) {
                    console.warn('⚠️ Aún hay contenido persistido detectado después de limpiar claves específicas');
                }
            }
            
            // 7. Limpiar URLESTUDIO de sessionStorage directamente
            if (window.urlStudyManager) {
                if (window.urlStudyManager.hasActiveStudy()) {
                    console.log('Limpiando estudioURL activo...');
                    window.urlStudyManager.clearActiveStudy();
                }
            }
            sessionStorage.removeItem('URLESTUDIO');
            localStorage.removeItem('URLESTUDIO');
            
            // 8. Si aún hay contenido persistido, limpiar TODAS las claves relacionadas
            if (hasRemainingContent || (studyId && patientId && localStorage.getItem(`editor_persistence_${studyId}_${patientId}`))) {
                console.warn('⚠️ Aún hay contenido persistido detectado, limpiando todas las claves relacionadas...');
                
                // Limpiar TODAS las claves que puedan estar relacionadas
                const allKeysToRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key && (
                        key.startsWith('editor_persistence_') ||
                        key.startsWith('editor_session_') ||
                        key.startsWith('tinymce_') ||
                        key.startsWith('audio_') ||
                        key === 'URLESTUDIO'
                    )) {
                        allKeysToRemove.push(key);
                    }
                }
                
                allKeysToRemove.forEach(key => {
                    localStorage.removeItem(key);
                    console.log('Clave eliminada:', key);
                });
                
                // Limpiar sessionStorage también
                for (let i = 0; i < sessionStorage.length; i++) {
                    const key = sessionStorage.key(i);
                    if (key && (
                        key.startsWith('editor_session_') ||
                        key === 'URLESTUDIO'
                    )) {
                        sessionStorage.removeItem(key);
                        console.log('Clave de sesión eliminada:', key);
                    }
                }
                
                // Limpiar estudioURL nuevamente
                if (window.urlStudyManager) {
                    window.urlStudyManager.clearActiveStudy();
                }
            }
            
            // 9. Verificación final - verificar directamente en localStorage para asegurar limpieza
            const finalCheck = studyId && patientId ? 
                !localStorage.getItem(`editor_persistence_${studyId}_${patientId}`) : true;
            
            console.log('✅ Caché de persistencia limpiado completamente');
            console.log('   - estudioURL limpiado:', !window.urlStudyManager?.hasActiveStudy());
            console.log('   - Persistencia del editor eliminada:', finalCheck);
            console.log('   - Avisos de cambios sin guardar desactivados');
            console.log('   - Estado del editor reseteado');
            
            if (!finalCheck) {
                console.error('❌ ERROR: Aún existe contenido persistido después de limpiar. Intentando limpieza agresiva...');
                // Limpieza agresiva final
                localStorage.removeItem(`editor_persistence_${studyId}_${patientId}`);
                localStorage.removeItem(`editor_session_${studyId}_${patientId}`);
                sessionStorage.removeItem(`editor_persistence_${studyId}_${patientId}`);
                sessionStorage.removeItem(`editor_session_${studyId}_${patientId}`);
            }
            
            // 10. PREVENIR que los listeners de TinyMCE guarden automáticamente después de limpiar
            // Configurar un flag temporal que indique que acabamos de guardar
            if (window.PersistenceModule) {
                window.PersistenceModule.hasUnsavedChanges = false;
                
                // Deshabilitar temporalmente el guardado automático por cambios
                // Agregar un flag para prevenir guardado inmediato
                window.PersistenceModule._preventAutoSave = true;
                
                // Rehabilitar después de 5 segundos (tiempo suficiente para que se complete la limpieza)
                setTimeout(() => {
                    if (window.PersistenceModule) {
                        window.PersistenceModule._preventAutoSave = false;
                        console.log('✅ Auto-save habilitado nuevamente (después de limpiar caché)');
                    }
                }, 5000);
            }
            
            // 11. NO reactivar auto-save automáticamente después de limpiar
            // El auto-save solo debe reactivarse cuando el usuario haga cambios nuevos
            // Mantenerlo desactivado por ahora
            console.log('⚠️ Auto-save mantenido DESACTIVADO después de guardar informe');
            console.log('   Se reactivará automáticamente cuando el usuario haga nuevos cambios');
            
            return true;
        } catch (error) {
            console.error('❌ Error limpiando caché de persistencia:', error);
            return false;
        }
    }
};
window.ReportsModule = ReportsModule;

/**
 * Nota: DICOMModule está definido en dicom.js
 * No redeclarar aquí para evitar conflictos
 */