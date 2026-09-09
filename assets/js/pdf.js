// Módulo de generación de PDF
const PDFModule = {
    // Configuración por defecto
    config: {
        filename: 'informe_medico.pdf',
        format: 'a4',
        margin: [40, 40, 40, 40], // [top, right, bottom, left]
        headerTemplate: `
            <div style="font-size: 10px; text-align: center; width: 100%;">
                <img src="logo.svg" style="height: 50px;">
                <p>TJS Medical</p>
            </div>
        `,
        footerTemplate: `
            <div style="font-size: 10px; text-align: center; width: 100%;">
                <p>Página <span class="pageNumber"></span> de <span class="totalPages"></span></p>
            </div>
        `
    },

    // Generar PDF desde contenido HTML
    generateFromHTML: async function(htmlContent, options = {}) {
        try {
            const config = { ...this.config, ...options };
            
            // Crear elemento temporal para el contenido
            const container = document.createElement('div');
            container.innerHTML = htmlContent;

            // Aplicar estilos para impresión
            this.applyPrintStyles(container);

            // Generar PDF usando html2pdf
            const pdf = await html2pdf()
                .set({
                    filename: config.filename,
                    margin: config.margin,
                    html2canvas: { scale: 2, useCORS: true },
                    jsPDF: { format: config.format, orientation: 'portrait' }
                })
                .from(container)
                .save();

            return pdf;
        } catch (error) {
            console.error('Error al generar PDF:', error);
            throw error;
        }
    },

    // Aplicar estilos para impresión
    applyPrintStyles: function(container) {
        // Estilos generales
        container.style.fontFamily = 'Arial, sans-serif';
        container.style.fontSize = '12px';
        container.style.lineHeight = '1.5';
        container.style.color = '#000';

        // Estilos para títulos
        const headings = container.querySelectorAll('h1, h2, h3, h4, h5, h6');
        headings.forEach(heading => {
            heading.style.marginBottom = '10px';
            heading.style.color = '#333';
        });

        // Estilos para párrafos
        const paragraphs = container.querySelectorAll('p');
        paragraphs.forEach(p => {
            p.style.marginBottom = '8px';
        });

        // Estilos para tablas
        const tables = container.querySelectorAll('table');
        tables.forEach(table => {
            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';
            table.style.marginBottom = '15px';

            const cells = table.querySelectorAll('td, th');
            cells.forEach(cell => {
                cell.style.border = '1px solid #ddd';
                cell.style.padding = '8px';
                cell.style.textAlign = 'left';
            });

            const headers = table.querySelectorAll('th');
            headers.forEach(header => {
                header.style.backgroundColor = '#f5f5f5';
            });
        });
    },

    // Generar PDF desde el editor de informes
    generateFromEditor: async function() {
        try {
            const editor = tinymce.get('reportEditor');
            if (!editor) {
                throw new Error('Editor no encontrado');
            }

            const content = editor.getContent();
            await this.generateFromHTML(content, {
                filename: `informe_${new Date().toISOString().split('T')[0]}.pdf`
            });

            return true;
        } catch (error) {
            console.error('Error al generar PDF desde editor:', error);
            throw error;
        }
    },

    // Previsualizar PDF
    previewPDF: async function(htmlContent) {
        try {
            const container = document.createElement('div');
            container.innerHTML = htmlContent;
            this.applyPrintStyles(container);

            const previewWindow = window.open('', '_blank');
            previewWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Previsualización de Informe</title>
                    <style>
                        body { margin: 0; padding: 20px; }
                        @media print {
                            body { padding: 0; }
                        }
                    </style>
                </head>
                <body>
                    ${container.innerHTML}
                </body>
                </html>
            `);
            previewWindow.document.close();
        } catch (error) {
            console.error('Error al previsualizar PDF:', error);
            throw error;
        }
    }
};