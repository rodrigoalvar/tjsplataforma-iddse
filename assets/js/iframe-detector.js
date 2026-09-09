/**
 * Script para detectar si la página está cargada en un iframe
 * y ocultar el sidebar en ese caso
 */
(function() {
    'use strict';
    
    // Detectar si estamos en un iframe
    const isInIframe = window.self !== window.top;
    
    if (isInIframe) {
        console.log('🔍 Página detectada en iframe, ocultando sidebar...');
        
        // Ocultar sidebar inmediatamente con CSS inline para evitar flash visual
        const immediateStyle = document.createElement('style');
        immediateStyle.id = 'iframe-sidebar-hide-immediate';
        immediateStyle.textContent = `
            #sidebarLayout .sidebar,
            #sidebarLayout .sidebar-overlay,
            #sidebarLayout .mobile-header,
            #sidebarLayout #sidebarToggle,
            #sidebarLayout #sidebarToggleDesktop,
            #sidebarLayout #sidebarToggleShow {
                display: none !important;
            }
            #sidebarLayout .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        `;
        document.head.appendChild(immediateStyle);
        
        // Esperar a que el DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', hideSidebar);
        } else {
            hideSidebar();
        }
        
        function hideSidebar() {
            // Ocultar el sidebar layout completo
            const sidebarLayout = document.getElementById('sidebarLayout');
            if (sidebarLayout) {
                // En lugar de ocultar completamente, ajustar el layout
                // para que el main-content ocupe todo el espacio
                sidebarLayout.classList.add('iframe-mode');
                
                // Ocultar el sidebar
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.style.display = 'none';
                }
                
                // Ocultar el overlay del sidebar
                const sidebarOverlay = document.getElementById('sidebarOverlay');
                if (sidebarOverlay) {
                    sidebarOverlay.style.display = 'none';
                }
                
                // Ocultar el botón de toggle del sidebar
                const sidebarToggle = document.getElementById('sidebarToggle');
                if (sidebarToggle) {
                    sidebarToggle.style.display = 'none';
                }
                
                const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
                if (sidebarToggleDesktop) {
                    sidebarToggleDesktop.style.display = 'none';
                }
                
                const sidebarToggleShow = document.getElementById('sidebarToggleShow');
                if (sidebarToggleShow) {
                    sidebarToggleShow.style.display = 'none';
                }
                
                // Ocultar el mobile header si existe
                const mobileHeader = document.querySelector('.mobile-header');
                if (mobileHeader) {
                    mobileHeader.style.display = 'none';
                }
                
                // Ajustar el main-content para que ocupe todo el espacio
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                    mainContent.style.width = '100%';
                }
                
                console.log('✅ Sidebar ocultado en modo iframe');
            }
            
            // Agregar estilos CSS para modo iframe (si no existe ya)
            if (!document.getElementById('iframe-mode-styles')) {
                const style = document.createElement('style');
                style.id = 'iframe-mode-styles';
                style.textContent = `
                    .layout-container.iframe-mode {
                        display: block !important;
                    }
                    .layout-container.iframe-mode .sidebar {
                        display: none !important;
                    }
                    .layout-container.iframe-mode .sidebar-overlay {
                        display: none !important;
                    }
                    .layout-container.iframe-mode .main-content {
                        margin-left: 0 !important;
                        width: 100% !important;
                        max-width: 100% !important;
                    }
                    .layout-container.iframe-mode .mobile-header {
                        display: none !important;
                    }
                    .layout-container.iframe-mode .workspace-container {
                        width: 100% !important;
                        max-width: 100% !important;
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        // Interceptar clics en enlaces del sidebar para comunicarlos al padre
        document.addEventListener('click', function(e) {
            const navLink = e.target.closest('.sidebar .nav-link, .nav-link');
            if (navLink && navLink.href && !navLink.href.startsWith('#') && !navLink.href.startsWith('javascript:')) {
                // Verificar si el enlace es para una sección que debe manejarse desde el contenedor
                const href = navLink.getAttribute('href');
                if (href && !href.startsWith('http') && !href.startsWith('//') && !href.startsWith('mailto:')) {
                    // Comunicar al padre (app-container) para que maneje la navegación
                    if (window.parent && window.parent !== window.self && window.parent.AppContainer) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        console.log('📡 Comunicando navegación al contenedor padre:', href);
                        
                        // Extraer sección del href si tiene data-section
                        let section = navLink.getAttribute('data-section');
                        if (!section && href.includes('app-container.html')) {
                            const urlParams = new URLSearchParams(href.split('?')[1] || '');
                            section = urlParams.get('section');
                        }
                        
                        // Enviar mensaje al padre
                        window.parent.postMessage({
                            type: 'navigate',
                            url: href,
                            section: section
                        }, '*');
                    }
                }
            }
        }, true);
        
        // Escuchar mensajes del padre (opcional, para sincronización)
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'sync') {
                console.log('📥 Mensaje recibido del contenedor:', e.data);
            }
        });
    }
})();
