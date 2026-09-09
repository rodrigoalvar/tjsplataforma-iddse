/**
 * Módulo para controlar la visibilidad y estado activo del sidebar basado en permisos GUI
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 */

const SidebarGUIManager = {
    /**
     * Bandera para evitar reaplicaciones mientras se está aplicando
     */
    isApplying: false,
    
    /**
     * Bandera para indicar que los permisos ya fueron aplicados
     */
    permissionsApplied: false,

    /** Nivel del usuario en sesión (root ve todos los ítems GUI) */
    _userNivel: null,

    /**
     * Mapeo de permisos GUI a selectores del sidebar
     */
    sidebarMapping: {
        'gui_dashboard': {
            selectors: ['a[href*="dashboard"]', 'a[href*="dashboard-unified"]'],
            text: 'Dashboard',
            hrefPatterns: ['dashboard']
        },
        'gui_estudios': {
            selectors: ['a[href*="paciente.html"]'],
            text: 'Estudios',
            hrefPatterns: ['paciente.html']
        },
        'gui_informes': {
            selectors: ['a[href*="editor.html"]'],
            text: 'Informes',
            hrefPatterns: ['editor.html']
        },
        'gui_gestion_informes': {
            selectors: ['a[href*="informes-manager.html"]'],
            text: 'Gestión Informes',
            hrefPatterns: ['informes-manager.html']
        },
        'gui_gestion_estudios': {
            selectors: ['a[href*="estudios-manager.html"]'],
            text: 'Gestión Estudios',
            hrefPatterns: ['estudios-manager.html']
        },
        'gui_gestion_pacientes': {
            selectors: ['a[href*="pacientes-manager.html"]'],
            text: 'Gestión Pacientes',
            hrefPatterns: ['pacientes-manager.html']
        },
        'gui_grabacion': {
            selectors: ['a[href*="audio-recorder.html"]'],
            text: 'Grabación',
            hrefPatterns: ['audio-recorder.html']
        },
        'gui_visor_dicom': {
            selectors: ['a[href*="viewer.html"]'],
            text: 'Visor DICOM',
            hrefPatterns: ['viewer.html']
        },
        'gui_workspace': {
            selectors: ['a[href*="workspace.html"]', 'a[href*="app-container.html?section=workspace"]', 'a[data-section="workspace"]'],
            text: 'WorkSpace',
            hrefPatterns: ['workspace.html', 'app-container.html?section=workspace']
        },
        'gui_gestion_usuarios': {
            selectors: ['a[href*="user-management.html"]'],
            text: 'Gestión Usuarios',
            hrefPatterns: ['user-management.html']
        },
        'gui_pacs_manager': {
            selectors: ['a[href*="pacs-manager.html"]'],
            text: 'PACS Manager',
            hrefPatterns: ['pacs-manager.html']
        },
        'gui_pacs_nodes_manager': {
            selectors: ['a[href*="pacs-nodes-manager.html"]'],
            text: 'PACS Nodes Manager',
            hrefPatterns: ['pacs-nodes-manager.html']
        },
        'gui_study_history_manager': {
            selectors: ['a[href*="study-history-manager.html"]'],
            text: 'Historial estudios',
            hrefPatterns: ['study-history-manager.html']
        },
        'gui_qa_publicacion': {
            selectors: ['a[href*="qa-publicacion/qa-manager.html"]', 'a[href*="qa-manager.html"]'],
            text: 'QA Control de Calidad',
            hrefPatterns: ['qa-publicacion/qa-manager.html', 'qa-manager.html']
        },
        'gui_cloud_storage': {
            selectors: ['a[href*="cloud-storage.html"]'],
            text: 'Cloud Storage',
            hrefPatterns: ['cloud-storage.html']
        },
        'gui_audit_manager': {
            selectors: ['a[href*="audit-manager.html"]'],
            text: 'Auditoría',
            hrefPatterns: ['audit-manager.html']
        },
        'gui_worklist': {
            selectors: ['a[href*="worklist.html"]'],
            text: 'Worklist',
            hrefPatterns: ['worklist.html']
        },
        'gui_ai_informes': {
            selectors: ['a[href*="ai-informes.html"]'],
            text: 'AI Informes',
            hrefPatterns: ['ai-informes.html']
        },
        'gui_configuracion': {
            selectors: [
                'a[href*="configuracion.html"]',
                'a[href*="configuracion"]',
                'a[href*="config"]'
            ],
            text: 'Configuración',
            hrefPatterns: ['configuracion.html', 'configuracion', 'config']
        },
        'gui_gestion_mensajes': {
            selectors: [
                'a[href*="modules/email/admin.php"]',
                'a[href*="email/admin.php"]',
                'a[id="linkGestionMensajes"]'
            ],
            text: 'Gestión Mensajes',
            hrefPatterns: ['modules/email/admin.php', 'email/admin.php', 'admin.php']
        }
    },

    /**
     * Buscar el sidebar principal (del app-container, no del workspace interno)
     */
    findMainSidebar() {
        // Estrategia 1: Si estamos en un iframe, SIEMPRE buscar en el documento principal primero
        try {
            if (window.self !== window.top && window.top.document) {
                const topMainNav = window.top.document.getElementById('mainSidebarNav');
                if (topMainNav) {
                    const sidebar = topMainNav.closest('.sidebar-nav') || topMainNav.parentElement;
                    if (sidebar) {
                        if (window.DEBUG_SIDEBAR) {
                            console.log('✅ Sidebar principal encontrado en documento padre (iframe)');
                        }
                        return sidebar;
                    }
                }
            }
        } catch (e) {
            // Cross-origin o acceso denegado, continuar con búsqueda local
            // Solo loguear si es un error real (no cross-origin normal)
            if (!e.message || !e.message.includes('Blocked a frame')) {
                console.warn('⚠️ No se pudo acceder al documento padre:', e);
            }
        }
        
        // Estrategia 2: Buscar el sidebar principal por ID (app-container.html)
        const mainSidebarNav = document.getElementById('mainSidebarNav');
        if (mainSidebarNav) {
            const sidebar = mainSidebarNav.closest('.sidebar-nav') || mainSidebarNav.parentElement;
            if (sidebar) {
                // Solo loguear en modo debug para reducir ruido en consola
                if (window.DEBUG_SIDEBAR) {
                    console.log('✅ Sidebar principal encontrado por ID mainSidebarNav');
                }
                return sidebar;
            }
        }
        
        // Estrategia 3: Buscar cualquier sidebar-nav, pero verificar que no sea del workspace interno
        const allSidebars = document.querySelectorAll('.sidebar-nav');
        // Preferir el sidebar que NO está dentro de un workspace-container
        for (let sb of allSidebars) {
            if (!sb.closest('.workspace-container') && !sb.closest('#workspaceFrame') && 
                !sb.closest('.workspace-layout')) {
                if (window.DEBUG_SIDEBAR) {
                    console.log('✅ Sidebar principal encontrado (excluyendo workspace interno)');
                }
                return sb;
            }
        }
        
        // Estrategia 4: Si no se encuentra ninguno que no sea del workspace, usar el primero
        // pero solo si no estamos en un iframe (para evitar usar el sidebar del workspace)
        if (window.self === window.top && allSidebars.length > 0) {
            if (window.DEBUG_SIDEBAR) {
                console.warn('⚠️ Usando primer sidebar encontrado (no se encontró mainSidebarNav)');
            }
            return allSidebars[0];
        }
        
        // Solo loguear si realmente no se encontró (puede ser normal en algunos contextos)
        if (window.DEBUG_SIDEBAR) {
            console.warn('⚠️ No se encontró sidebar principal');
        }
        return null;
    },

    /**
     * Ocultar todos los elementos del sidebar inicialmente
     * Solo se debe llamar antes de aplicar permisos, no después
     */
    hideAllSidebarItems() {
        // No ocultar si ya se están aplicando permisos (evitar conflictos)
        if (this.isApplying) {
            return; // Silenciosamente omitir si ya se están aplicando permisos
        }
        
        // No ocultar si los permisos ya fueron aplicados
        if (this.permissionsApplied) {
            return; // Silenciosamente omitir si los permisos ya fueron aplicados
        }
        
        const sidebar = this.findMainSidebar();
        if (!sidebar) return;
        
        // Verificar si ya hay elementos visibles (permisos ya aplicados)
        const hasVisibleItems = sidebar.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
        if (hasVisibleItems) {
            // Si ya hay elementos visibles, marcar que los permisos están aplicados y no ocultar
            this.permissionsApplied = true;
            return;
        }
        
        const allNavItems = Array.from(sidebar.querySelectorAll('.nav-item'));
        // Solo ocultar si hay elementos y no están visibles
        if (allNavItems.length > 0) {
            allNavItems.forEach(item => {
                item.classList.remove('sidebar-item-visible');
            });
        }
    },

    /**
     * Inicializar el control del sidebar
     */
    async init() {
        try {
            // Si estamos en un iframe y no estamos en app-container, no inicializar
            // (el sidebar del workspace debe estar oculto cuando está en iframe)
            if (window.self !== window.top) {
                // Verificar si estamos en app-container (el padre)
                // Si no, estamos en un iframe hijo y no debemos gestionar el sidebar
                const isAppContainer = window.location.pathname.includes('app-container.html');
                if (!isAppContainer) {
                    console.log('🔍 Sidebar GUI Manager: Omitiendo inicialización en iframe hijo');
                    return;
                }
            }
            
            console.log('🔍 Inicializando Sidebar GUI Manager...');
            
            // Verificar si los permisos ya fueron aplicados
            if (this.permissionsApplied) {
                console.log('ℹ️ Permisos ya aplicados, omitiendo init()');
                return;
            }
            
            // Verificar si ya hay elementos visibles (puede que los permisos ya se hayan aplicado)
            const sidebar = this.findMainSidebar();
            const hasVisibleItems = sidebar && sidebar.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
            
            // Si ya hay elementos visibles, significa que los permisos ya se aplicaron
            // No ocultar en este caso para evitar conflictos
            if (!hasVisibleItems) {
                // Solo ocultar si no hay elementos visibles (evitar ocultar después de aplicar permisos)
                this.hideAllSidebarItems();
            } else {
                console.log('ℹ️ Elementos ya visibles detectados, omitiendo hideAllSidebarItems (permisos ya aplicados)');
                // Marcar que los permisos están aplicados
                this.permissionsApplied = true;
                // Si ya hay elementos visibles, no necesitamos aplicar permisos de nuevo
                // Solo retornar para evitar aplicar permisos dos veces
                return;
            }
            
            // Esperar a que el DOM esté completamente cargado
            await this.waitForSidebar();
            
            // Verificar nuevamente si hay elementos visibles antes de ocultar
            const sidebarAfterWait = this.findMainSidebar();
            const hasVisibleItemsAfterWait = sidebarAfterWait && sidebarAfterWait.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
            
            // Si después de esperar ya hay elementos visibles, no hacer nada más
            if (hasVisibleItemsAfterWait) {
                console.log('ℹ️ Elementos ya visibles después de esperar, omitiendo aplicación de permisos (ya aplicados)');
                this.permissionsApplied = true;
                return;
            }
            
            // Obtener permisos del usuario actual
            const permisos = await this.getUserPermissions();
            
            if (!permisos) {
                console.warn('⚠️ No se pudieron obtener permisos del usuario');
                // Si no hay permisos, mostrar todos los elementos por defecto
                this.showAllSidebarItems();
                return;
            }
            
            console.log('👤 Permisos GUI del usuario:', permisos);
            
            // Aplicar controles de visibilidad y estado activo
            this.applySidebarControls(permisos);
            
            // Marcar que los permisos fueron aplicados
            this.permissionsApplied = true;
            
        } catch (error) {
            console.error('❌ Error inicializando Sidebar GUI Manager:', error);
            // En caso de error, mostrar todos los elementos por defecto
            this.showAllSidebarItems();
        }
    },

    /**
     * Mostrar todos los elementos del sidebar (fallback)
     */
    showAllSidebarItems() {
        const sidebar = this.findMainSidebar();
        if (!sidebar) return;
        
        const allNavItems = Array.from(sidebar.querySelectorAll('.nav-item'));
        allNavItems.forEach(item => {
            item.classList.add('sidebar-item-visible');
        });
    },

    /**
     * Esperar a que el sidebar esté disponible en el DOM
     */
    waitForSidebar() {
        return new Promise((resolve) => {
            const sidebar = this.findMainSidebar();
            if (sidebar) {
                // Verificar que tenga elementos dentro
                const navItems = sidebar.querySelectorAll('.nav-item');
                if (navItems.length > 0) {
                    resolve();
                    return;
                }
            }
            
            let attempts = 0;
            const maxAttempts = 50; // 5 segundos máximo
            
            const checkSidebar = () => {
                attempts++;
                const sidebar = this.findMainSidebar();
                if (sidebar) {
                    const navItems = sidebar.querySelectorAll('.nav-item');
                    if (navItems.length > 0) {
                        resolve();
                        return;
                    }
                }
                
                if (attempts < maxAttempts) {
                    setTimeout(checkSidebar, 100);
                } else {
                    console.warn('⚠️ Sidebar no encontrado después de múltiples intentos');
                    resolve(); // Resolver de todas formas para no bloquear
                }
            };
            
            checkSidebar();
        });
    },

    /**
     * Obtener permisos del usuario actual
     */
    async getUserPermissions() {
        try {
            // Construir ruta absoluta desde la raíz de la aplicación
            const pathname = window.location.pathname;
            let apiBaseUrl;
            
            // Si estamos en la raíz (ej: /index.html, /dashboard.html)
            if (pathname === '/' || pathname.match(/^\/[^\/]+\.(html|php)$/)) {
                apiBaseUrl = 'api/auth/validate-session-simple.php';
            }
            // Si estamos en components/
            else if (pathname.includes('/components/')) {
                apiBaseUrl = '../api/auth/validate-session-simple.php';
            }
            // Si estamos en modules/ (ej: /modules/email/admin.php)
            else if (pathname.includes('/modules/')) {
                // Calcular cuántos niveles subir: /modules/email/admin.php -> ../../ (subir email y modules)
                const pathParts = pathname.split('/').filter(p => p); // ['modules', 'email', 'admin.php']
                const depth = pathParts.length - 1; // 2 niveles (modules y email)
                apiBaseUrl = '../'.repeat(depth) + 'api/auth/validate-session-simple.php';
            }
            // Para otros casos, calcular profundidad
            else {
                const pathParts = pathname.split('/').filter(p => p);
                const depth = pathParts.length;
                apiBaseUrl = '../'.repeat(depth) + 'api/auth/validate-session-simple.php';
            }
            
            const response = await fetch(apiBaseUrl);
            const result = await response.json();
            
            if (!result.success || !result.user) {
                return null;
            }
            
            // Manejar permisos que pueden venir como array o string
            let permisos = result.user.permisos || [];
            if (typeof permisos === 'string') {
                try {
                    permisos = JSON.parse(permisos);
                } catch (e) {
                    permisos = [permisos];
                }
            }
            if (!Array.isArray(permisos)) {
                permisos = [];
            }

            this._userNivel = result.user.nivel || result.user.level || null;
            
            return permisos;
            
        } catch (error) {
            console.error('Error obteniendo permisos:', error);
            return null;
        }
    },

    /**
     * Normalizar href para comparación (remover rutas relativas y caracteres especiales)
     */
    normalizeHref(href) {
        if (!href) return '';
        // Remover rutas relativas (../, ./) y normalizar
        let normalized = href.replace(/^\.\.\//g, '').replace(/^\.\//g, '');
        // Remover query strings y hashes
        normalized = normalized.split('?')[0].split('#')[0];
        // Remover barras iniciales
        normalized = normalized.replace(/^\//g, '');
        return normalized.toLowerCase();
    },

    /**
     * Re-aplicar controles del sidebar (útil cuando cambias de página o se modifica el DOM)
     */
    async reapply() {
        // Evitar reaplicaciones múltiples simultáneas
        if (this.isApplying) {
            // Silenciosamente omitir si ya hay una re-aplicación en progreso
            return;
        }
        
        this.isApplying = true;
        // Log solo en modo debug o cuando realmente es necesario
        if (window.DEBUG_SIDEBAR) {
            console.log('🔄 Re-aplicando controles del sidebar...', {
                isInIframe: window.self !== window.top,
                location: window.location.pathname,
                hasMainSidebarNav: !!document.getElementById('mainSidebarNav')
            });
        }
        
        try {
            // Verificar que el sidebar principal existe antes de continuar
            const sidebar = this.findMainSidebar();
            if (!sidebar) {
                console.warn('⚠️ No se encontró sidebar principal, omitiendo re-aplicación');
                return;
            }
            
            const permisos = await this.getUserPermissions();
            if (permisos) {
                if (window.DEBUG_SIDEBAR) {
                    console.log('👤 Permisos obtenidos:', permisos.length, 'permisos');
                }
                this.applySidebarControls(permisos);
                // Marcar que los permisos fueron aplicados
                this.permissionsApplied = true;
            } else {
                console.warn('⚠️ No se pudieron obtener permisos, mostrando todos los elementos');
                this.showAllSidebarItems();
                // Marcar que los permisos fueron aplicados (aunque sea el fallback)
                this.permissionsApplied = true;
            }
        } catch (error) {
            console.error('❌ Error en reapply:', error);
            // En caso de error, mostrar todos los elementos para no dejar el sidebar vacío
            this.showAllSidebarItems();
        } finally {
            // Liberar la bandera después de un pequeño delay para evitar bucles
            setTimeout(() => {
                this.isApplying = false;
            }, 200);
        }
    },

    /**
     * Aplicar controles de visibilidad y estado activo al sidebar
     */
    applySidebarControls(permisos) {
        // Evitar aplicar si ya se está aplicando
        if (this.isApplying) {
            return;
        }
        
        const hasAllPermission = permisos.includes('all') || this._userNivel === 'root';
        const sidebar = this.findMainSidebar();
        
        if (!sidebar) {
            console.warn('⚠️ Sidebar no encontrado');
            return;
        }
        
        // Marcar que los permisos están siendo aplicados
        this.permissionsApplied = true;
        
        // Obtener todos los enlaces del sidebar una vez
        const allLinks = Array.from(sidebar.querySelectorAll('.nav-link'));
        const allNavItems = Array.from(sidebar.querySelectorAll('.nav-item'));
        
        // Solo loguear en modo debug
        if (window.DEBUG_SIDEBAR) {
            console.log(`📊 Aplicando controles del sidebar:`, {
                totalLinks: allLinks.length,
                totalNavItems: allNavItems.length,
                hasAllPermission: hasAllPermission,
                permisosCount: permisos.length
            });
        }
        
        // Iterar sobre cada permiso GUI
        Object.keys(this.sidebarMapping).forEach(permissionKey => {
            const mapping = this.sidebarMapping[permissionKey];
            const hasPermission = hasAllPermission || permisos.includes(permissionKey);
            
            // Buscar elementos del sidebar que coincidan
            let elements = [];
            
            // Estrategia 1: Buscar por selectores CSS
            mapping.selectors.forEach(selector => {
                try {
                    const found = Array.from(sidebar.querySelectorAll(selector));
                    // Evitar duplicados
                    found.forEach(el => {
                        if (!elements.includes(el)) {
                            elements.push(el);
                        }
                    });
                } catch (e) {
                    console.warn(`⚠️ Selector inválido para ${permissionKey}:`, selector);
                }
            });
            
            // Estrategia 2: Buscar por patrones de href normalizados
            if (elements.length === 0) {
                allLinks.forEach(link => {
                    const href = this.normalizeHref(link.getAttribute('href'));
                    const matches = mapping.hrefPatterns.some(pattern => {
                        const normalizedPattern = pattern.toLowerCase();
                        return href.includes(normalizedPattern) || 
                               href.endsWith(normalizedPattern) ||
                               href.includes(normalizedPattern.replace('.html', ''));
                    });
                    if (matches && !elements.includes(link)) {
                        elements.push(link);
                    }
                });
            }
            
            // Estrategia 3: Buscar por texto si aún no se encontraron elementos
            if (elements.length === 0) {
                allLinks.forEach(link => {
                    const text = link.textContent.trim().toLowerCase();
                    const mappingText = mapping.text.toLowerCase();
                    if ((text.includes(mappingText) || mappingText.includes(text)) && 
                        !elements.includes(link)) {
                        elements.push(link);
                    }
                });
            }
            
            // Aplicar controles a cada elemento encontrado
            elements.forEach(element => {
                const navItem = element.closest('.nav-item');
                if (!navItem) return;
                
                if (hasPermission) {
                    // Mostrar elemento y permitir que esté activo
                    navItem.classList.add('sidebar-item-visible');
                    // No removemos la clase 'active' si la tiene, solo permitimos visibilidad
                } else {
                    // Ocultar elemento si no tiene permiso
                    navItem.classList.remove('sidebar-item-visible');
                }
            });
            
            // Fallback especial para Configuración: si tiene permiso pero no se encontró el elemento,
            // buscar por texto o icono de forma más agresiva
            if (permissionKey === 'gui_configuracion' && hasPermission && elements.length === 0) {
                // Buscar por texto "Configuración" o icono fa-cog
                allLinks.forEach(link => {
                    const linkText = link.textContent.trim().toLowerCase();
                    const hasConfigText = linkText.includes('configuración') || linkText.includes('configuracion');
                    const hasCogIcon = link.querySelector('i.fa-cog, i.fas.fa-cog');
                    
                    if ((hasConfigText || hasCogIcon) && !elements.includes(link)) {
                        const navItem = link.closest('.nav-item');
                        if (navItem) {
                            navItem.classList.add('sidebar-item-visible');
                            elements.push(link);
                            console.log(`✅ Configuración encontrada mediante búsqueda alternativa`);
                        }
                    }
                });
            }
            
            // Solo loguear en modo debug
            if (window.DEBUG_SIDEBAR && elements.length > 0) {
                console.log(`✅ ${mapping.text}: ${hasPermission ? 'VISIBLE' : 'OCULTO'} (${elements.length} elemento(s))`);
            }
            // No mostrar mensajes cuando no se encuentran elementos - es normal que algunos no existan en todas las páginas
        });
        
        // Verificación final: Asegurar que Configuración siempre esté visible si el usuario tiene permiso
        const hasConfigPermission = hasAllPermission || permisos.includes('gui_configuracion');
        if (hasConfigPermission) {
            const allNavItems = Array.from(sidebar.querySelectorAll('.nav-item'));
            let configFound = false;
            
            allNavItems.forEach(navItem => {
                const link = navItem.querySelector('.nav-link');
                if (link) {
                    const linkText = link.textContent.trim().toLowerCase();
                    const href = link.getAttribute('href') || '';
                    const hasConfigText = linkText.includes('configuración') || linkText.includes('configuracion');
                    const hasCogIcon = link.querySelector('i.fa-cog, i.fas.fa-cog, i.fas.fa-cog');
                    const hasConfigHref = href.includes('configuracion') || href.includes('config');
                    
                    if (hasConfigText || hasCogIcon || hasConfigHref) {
                        configFound = true;
                        if (!navItem.classList.contains('sidebar-item-visible')) {
                            navItem.classList.add('sidebar-item-visible');
                            console.log('✅ Configuración forzada a visible (verificación final)', {
                                linkText,
                                href,
                                hasConfigText,
                                hasCogIcon: !!hasCogIcon,
                                hasConfigHref
                            });
                        }
                    }
                }
            });
            
            // No mostrar mensaje si no se encuentra - es normal que Configuración no exista en todas las páginas
        }
        
        // Verificación final: Contar cuántos elementos están visibles
        const visibleItems = Array.from(sidebar.querySelectorAll('.nav-item.sidebar-item-visible'));
        
        // Solo loguear advertencias si hay un problema real
        if (visibleItems.length === 0 && allNavItems.length > 0) {
            console.warn('⚠️ ADVERTENCIA: Ningún elemento del sidebar está visible después de aplicar permisos');
            console.warn('   Esto puede indicar un problema con los permisos o con la búsqueda de elementos');
        } else if (window.DEBUG_SIDEBAR) {
            console.log(`✅ Controles aplicados: ${visibleItems.length} elementos visibles de ${allNavItems.length} totales`);
        }
    },

    /**
     * Oculta o restaura el ítem WorkSpace del sidebar según el estado multi-monitor.
     * Llamado por MultiMonitorManager cuando el workspace se abre/cierra en ventana separada.
     * @param {boolean} detached - true cuando workspace está en monitor secundario
     */
    setMultimonitorDetached(detached) {
        const selectors = [
            'a[href*="app-container.html?section=workspace"]',
            'a[data-section="workspace"]',
            'a[href*="workspace.html"]'
        ];

        const sidebar = this.findMainSidebar();
        if (!sidebar) return;

        let navItem = null;
        for (const sel of selectors) {
            const el = sidebar.querySelector(sel);
            if (el) { navItem = el.closest('.nav-item') || el.parentElement; break; }
        }
        if (!navItem) return;

        if (detached) {
            navItem.style.setProperty('display', 'none', 'important');
            navItem.dataset.mmDetached = '1';
            if (window.DEBUG_SIDEBAR) console.log('🖥️ SidebarGUIManager: WorkSpace ocultado (multi-monitor detached)');
        } else {
            if (navItem.dataset.mmDetached === '1') {
                navItem.style.removeProperty('display');
                navItem.classList.add('sidebar-item-visible');
                delete navItem.dataset.mmDetached;
                if (window.DEBUG_SIDEBAR) console.log('🖥️ SidebarGUIManager: WorkSpace restaurado (multi-monitor closed)');
            }
        }
    },

    /**
     * Ruta base hacia la raíz del proyecto (según dónde se cargó sidebar-gui-manager.js)
     */
    getAssetBasePath() {
        const script = document.querySelector('script[src*="sidebar-gui-manager"]');
        if (!script) return '';
        const src = (script.getAttribute('src') || '').split('?')[0];
        const match = src.match(/^(.*)assets\/js\/sidebar-gui-manager\.js$/);
        return match ? match[1] : '';
    },

    getVersionJsonUrl() {
        return this.getAssetBasePath() + 'version.json';
    },

    /**
     * Muestra la versión del sistema en el footer del sidebar
     */
    async renderSidebarVersion() {
        try {
            const footers = document.querySelectorAll('.sidebar-footer');
            if (!footers.length) return;

            let data = window.APP_VERSION || null;
            if (!data) {
                const res = await fetch(this.getVersionJsonUrl(), { cache: 'no-store' });
                if (!res.ok) return;
                data = await res.json();
            }

            const label = 'v' + (data.version || '');
            footers.forEach(footer => {
                if (footer.querySelector('.sidebar-version')) return;
                const el = document.createElement('div');
                el.className = 'sidebar-version text-center mb-2';
                el.innerHTML = `<small class="sidebar-version-label">${label}</small>`;
                const logout = footer.querySelector('.logout-btn');
                if (logout) {
                    footer.insertBefore(el, logout);
                } else {
                    footer.prepend(el);
                }
            });
        } catch (e) {
            if (window.DEBUG_SIDEBAR) {
                console.warn('No se pudo mostrar versión en sidebar:', e);
            }
        }
    }
};

// Inicializar inmediatamente para evitar flash visual
(function() {
    // Si estamos en un iframe y no estamos en app-container, no inicializar
    // (el sidebar del workspace debe estar oculto cuando está en iframe)
    const isInIframe = window.self !== window.top;
    const isAppContainer = window.location.pathname.includes('app-container.html');
    
    if (isInIframe && !isAppContainer) {
        console.log('🔍 Sidebar GUI Manager: Omitiendo auto-inicialización en iframe hijo');
        return; // No ejecutar nada si estamos en un iframe hijo
    }
    
    // Función para inicializar de forma segura (verificando si ya hay permisos aplicados)
    const safeInit = () => {
        // Verificar si los permisos ya fueron aplicados
        if (SidebarGUIManager.permissionsApplied) {
            console.log('ℹ️ Inicialización automática: Permisos ya aplicados, omitiendo');
            return; // No inicializar si los permisos ya están aplicados
        }
        
        // Verificar si ya hay elementos visibles (permisos ya aplicados)
        const sidebar = SidebarGUIManager.findMainSidebar();
        const hasVisibleItems = sidebar && sidebar.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
        
        if (hasVisibleItems) {
            console.log('ℹ️ Inicialización automática: Elementos ya visibles, omitiendo (permisos ya aplicados)');
            SidebarGUIManager.permissionsApplied = true; // Marcar que los permisos están aplicados
            return; // No inicializar si los permisos ya están aplicados
        }
        
        // Solo ocultar e inicializar si no hay elementos visibles
        SidebarGUIManager.hideAllSidebarItems();
        SidebarGUIManager.init();
    };
    
    // Ocultar elementos del sidebar inmediatamente si el DOM ya está disponible
    if (document.readyState !== 'loading') {
        safeInit();
    } else {
        // Ocultar elementos tan pronto como el DOM esté disponible
        document.addEventListener('DOMContentLoaded', () => {
            safeInit();
        });
        
        // También intentar ocultar inmediatamente si el sidebar ya existe
        const checkSidebar = setInterval(() => {
            const sidebar = SidebarGUIManager.findMainSidebar();
            if (sidebar) {
                const hasVisibleItems = sidebar.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
                if (!hasVisibleItems) {
                    SidebarGUIManager.hideAllSidebarItems();
                }
                clearInterval(checkSidebar);
            }
        }, 10);
        
        // Limpiar el intervalo después de 2 segundos
        setTimeout(() => clearInterval(checkSidebar), 2000);
    }
    
    // Observar cambios en el DOM del sidebar para re-aplicar controles si es necesario
    const sidebarObserver = new MutationObserver((mutations) => {
        // Ignorar si ya se está aplicando (evitar bucles)
        if (SidebarGUIManager.isApplying) {
            return;
        }
        
        let shouldReapply = false;
        mutations.forEach((mutation) => {
            // Solo reaccionar a cambios en childList (agregar/remover elementos)
            // NO reaccionar a cambios en attributes (clases) que nosotros mismos hacemos
            if (mutation.type === 'childList') {
                const target = mutation.target;
                // Solo reaccionar si se agregan o remueven elementos del sidebar
                if (target.classList && target.classList.contains('sidebar-nav')) {
                    // Verificar que realmente se agregaron/removieron elementos, no solo cambios de clase
                    if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                        shouldReapply = true;
                    }
                } else if (target.closest && target.closest('.sidebar-nav')) {
                    // Verificar que realmente se agregaron/removieron elementos
                    if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                        shouldReapply = true;
                    }
                }
            }
            // Ignorar cambios en attributes (clases) para evitar bucles
        });
        
        if (shouldReapply) {
            // Re-aplicar controles después de un delay más largo para evitar múltiples ejecuciones
            clearTimeout(window.sidebarReapplyTimeout);
            window.sidebarReapplyTimeout = setTimeout(() => {
                // Verificar nuevamente antes de reaplicar
                if (!SidebarGUIManager.isApplying) {
                    // Verificar si ya hay elementos visibles antes de reaplicar
                    const sidebar = SidebarGUIManager.findMainSidebar();
                    const hasVisibleItems = sidebar && sidebar.querySelectorAll('.nav-item.sidebar-item-visible').length > 0;
                    
                    if (!hasVisibleItems) {
                        SidebarGUIManager.reapply();
                    }
                }
            }, 500);
        }
    });
    
    // Iniciar observación cuando el sidebar esté disponible
    const startObserving = () => {
        // No observar si estamos en un iframe hijo
        if (isInIframe && !isAppContainer) {
            return;
        }
        
        const sidebar = SidebarGUIManager.findMainSidebar();
        if (sidebar) {
            // Solo observar childList, NO attributes para evitar bucles
            sidebarObserver.observe(sidebar, {
                childList: true,
                subtree: true
                // Removido: attributes: true, attributeFilter: ['class']
            });
        } else {
            setTimeout(startObserving, 100);
        }
    };
    
    if (document.readyState !== 'loading') {
        startObserving();
    } else {
        document.addEventListener('DOMContentLoaded', startObserving);
    }

    const renderVersion = () => SidebarGUIManager.renderSidebarVersion();
    if (document.readyState !== 'loading') {
        renderVersion();
    } else {
        document.addEventListener('DOMContentLoaded', renderVersion);
    }
})();

// Hacer disponible globalmente para poder llamarlo manualmente si es necesario
window.SidebarGUIManager = SidebarGUIManager;


