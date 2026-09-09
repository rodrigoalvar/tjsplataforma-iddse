/**
 * Multi-Monitor Manager — Módulo Multi-Monitor WorkSpace
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 *
 * Permite abrir WorkSpace en un monitor secundario detectado.
 * Se carga condicionalmente en dashboard-unified.html solo cuando
 * el usuario tiene el permiso `feature_multimonitor`.
 *
 * Comunicación entre ventanas: BroadcastChannel ('mm-workspace-state')
 * Persistencia de estado: localStorage ('mm_workspace_state')
 */

const MultiMonitorManager = (() => {

    const CHANNEL_NAME      = 'mm-workspace-state';
    const LS_KEY            = 'mm_workspace_state';
    const WIN_NAME          = 'tjsmedical_workspace_monitor';
    const WORKSPACE_URL     = 'components/workspace.html';
    // Abre workspace sin sidebar (modo pantalla dedicada)
    const WORKSPACE_URL_MM  = 'components/workspace.html?mm_mode=detached';

    let _channel        = null;
    let _workspaceWin   = null;
    let _indicator      = null;
    let _navItem        = null;
    let _isDetached     = false;

    // ─── Window Management API ────────────────────────────────────────────────

    /**
     * Solicita el permiso window-management bajo user gesture.
     * Devuelve el objeto ScreenDetails o null si no disponible/denegado.
     */
    async function _fetchScreenDetails() {
        if (!('getScreenDetails' in window)) return null;
        try {
            return await window.getScreenDetails();
        } catch (_) {
            return null;
        }
    }

    /**
     * Resultado del análisis de pantallas: qué fuente se usó y la lista.
     * @typedef {{ screens: Array, source: 'api'|'api-partial'|'estimated', apiCount: number }} ScreensResult
     */

    /**
     * Normaliza una pantalla recibida de la API.
     * Brave devuelve availWidth/Height = 0 por privacidad;
     * usamos las dimensiones del monitor actual como estimación.
     */
    function _normalizeScreen(s, idx, fallbackW, fallbackH) {
        const w = (s.availWidth  > 0) ? s.availWidth  : (s.width  > 0 ? s.width  : fallbackW);
        const h = (s.availHeight > 0) ? s.availHeight : (s.height > 0 ? s.height : fallbackH);
        return {
            label:        s.label || ('Monitor ' + (idx + 1)),
            left:         s.availLeft,
            top:          s.availTop,
            width:        w,
            height:       h,
            isPrimary:    !!s.isPrimary,
            fromApi:      true,
            dimEstimated: (s.availWidth === 0 || s.availHeight === 0),
            estimated:    false
        };
    }

    /**
     * Genera posiciones estimadas para N monitores en una fila horizontal.
     * Cubre el caso más común (monitores en fila izquierda→derecha).
     * Se complementa con las posiciones a la izquierda del primario.
     */
    function _buildEstimatedScreens(primaryScreen, totalEstimated) {
        const sw = primaryScreen ? primaryScreen.width  : window.screen.width;
        const sh = primaryScreen ? primaryScreen.height : window.screen.height;
        const screens = primaryScreen ? [primaryScreen] : [{
            label: 'Este monitor', left: 0, top: 0,
            width: window.screen.availWidth, height: window.screen.availHeight,
            isPrimary: true, fromApi: false, dimEstimated: false, estimated: false
        }];

        // Monitores a la derecha: Monitor 2, 3, 4 ...
        for (let i = 1; i <= totalEstimated; i++) {
            screens.push({
                label:     'Monitor ' + (i + 1) + ' →',
                left:      sw * i,
                top:       0,
                width:     sw,
                height:    sh,
                isPrimary: false, fromApi: false, dimEstimated: false, estimated: true
            });
        }

        // Opción a la izquierda del primario (configuraciones no estándar)
        screens.push({
            label:     '← Monitor izquierda',
            left:      -sw,
            top:       0,
            width:     sw,
            height:    sh,
            isPrimary: false, fromApi: false, dimEstimated: false, estimated: true
        });

        return screens;
    }

    /**
     * Construye la lista de pantallas para el picker.
     *
     * Casos:
     * A) API devuelve 2+ pantallas con datos reales → 'api' (Chrome/Edge)
     * B) API devuelve 2+ pantallas pero dims=0      → 'api-partial' (Brave)
     * C) API devuelve 1 pantalla                    → completar con estimadas
     * D) API no disponible (Firefox) o denegada     → todo estimado
     *
     * @returns {{ screens: Array, source: string, apiCount: number }}
     */
    async function _getScreensInfo() {
        const details   = await _fetchScreenDetails();
        const fallbackW = window.screen.width;
        const fallbackH = window.screen.height;
        const apiCount  = details && details.screens ? details.screens.length : 0;

        // ── Caso A/B: API devuelve 2+ pantallas ──────────────────────────────
        if (apiCount > 1) {
            const screens = details.screens.map((s, i) => _normalizeScreen(s, i, fallbackW, fallbackH));
            const allDimsReal = screens.every(s => !s.dimEstimated);
            return { screens, source: allDimsReal ? 'api' : 'api-partial', apiCount };
        }

        // ── Caso C: API devuelve 1 pantalla (Brave devuelve solo la actual) ──
        const primaryFromApi = (apiCount === 1)
            ? _normalizeScreen(details.screens[0], 0, fallbackW, fallbackH)
            : null;

        // ── Caso D: Sin API → pantalla actual desde window.screen ───────────
        const primary = primaryFromApi || {
            label: 'Este monitor', left: 0, top: 0,
            width: window.screen.availWidth, height: window.screen.availHeight,
            isPrimary: true, fromApi: !!primaryFromApi, dimEstimated: false, estimated: false
        };

        // Generar 5 posiciones estimadas a la derecha (cubre hasta 6 monitores en fila)
        const screens = _buildEstimatedScreens(primary, 5);
        return { screens, source: 'estimated', apiCount };
    }

    // ─── Utilidades ─────────────────────────────────────────────────────────

    // ─── Interceptor de window.open ──────────────────────────────────────────
    // Mientras workspace está detached, redirige window.open(_blank) al monitor
    // donde está workspace para que los estudios se abran en el monitor correcto.

    let _origWindowOpen = null;

    /**
     * Devuelve un features string con las coordenadas actuales de _workspaceWin.
     * Esto permite abrir nuevas ventanas en el mismo monitor que workspace.
     */
    function _workspaceFeatures() {
        if (!_workspaceWin || _workspaceWin.closed) return null;
        try {
            const x = _workspaceWin.screenX !== undefined ? _workspaceWin.screenX : (_workspaceWin.screenLeft || 0);
            const y = _workspaceWin.screenY !== undefined ? _workspaceWin.screenY : (_workspaceWin.screenTop  || 0);
            const w = _workspaceWin.outerWidth  || window.screen.availWidth;
            const h = _workspaceWin.outerHeight || window.screen.availHeight;
            return `left=${x},top=${y},width=${w},height=${h},toolbar=no,menubar=no,status=no,scrollbars=yes,resizable=yes`;
        } catch (_) { return null; }
    }

    /**
     * Guarda las coordenadas del monitor de workspace en localStorage.
     * mm-study-redirect.js (en otras páginas) las usa para abrir en el monitor correcto.
     */
    function _saveWorkspaceScreen(left, top, width, height) {
        try {
            localStorage.setItem('mm_workspace_screen', JSON.stringify({ left, top, width, height, ts: Date.now() }));
        } catch(_) {}
    }

    function _startInterceptingOpens() {
        if (_origWindowOpen) return;                         // ya está interceptado
        _origWindowOpen = window.open.bind(window);
        window.open = function(url, target, features) {
            if (target === '_blank' && _isDetached && url) {
                // Estrategia 1: abrir directamente en el monitor de workspace usando sus coordenadas.
                // Esto es más confiable que BroadcastChannel porque evita intermediarios.
                const feat = _workspaceFeatures();
                if (feat) {
                    try {
                        const win = _origWindowOpen(url, '_blank', feat);
                        if (win) return win;
                    } catch (_) {}
                }

                // Estrategia 2: pedir a workspace que lo abra desde su contexto (mismo monitor)
                try {
                    if (_channel) {
                        _channel.postMessage({ type: 'mm-open-url', url: url });
                        return null;
                    }
                } catch(_) {}

                // Estrategia 3: postMessage directo
                try {
                    if (_workspaceWin && !_workspaceWin.closed) {
                        _workspaceWin.postMessage({ type: 'mm-open-url', url: url }, '*');
                        return null;
                    }
                } catch(_) {}
            }
            return _origWindowOpen(url, target, features);
        };
    }

    function _stopInterceptingOpens() {
        if (_origWindowOpen) {
            window.open = _origWindowOpen;
            _origWindowOpen = null;
        }
    }

    function _setState(open, screenLabel) {
        _isDetached = open;
        try {
            if (open) {
                localStorage.setItem(LS_KEY, JSON.stringify({ open: true, screen: screenLabel || '', ts: Date.now() }));
                _startInterceptingOpens();   // estudios → monitor workspace
            } else {
                localStorage.removeItem(LS_KEY);
                _stopInterceptingOpens();    // restaurar comportamiento normal
            }
        } catch (_) {}
    }

    function _getStoredState() {
        try {
            const raw = localStorage.getItem(LS_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (_) { return null; }
    }

    function _findWorkspaceNavItem() {
        const selectors = [
            'a[href*="app-container.html?section=workspace"]',
            'a[data-section="workspace"]',
            'a[href*="workspace.html"]'
        ];
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el) return el.closest('.nav-item') || el.parentElement;
        }
        return null;
    }

    function _escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ─── Sidebar ─────────────────────────────────────────────────────────────

    function _hideWorkspaceSidebarItem() {
        if (!_navItem) _navItem = _findWorkspaceNavItem();
        if (_navItem) {
            _navItem.style.setProperty('display', 'none', 'important');
            _navItem.dataset.mmHidden = '1';
        }
        if (window.SidebarGUIManager) window.SidebarGUIManager.setMultimonitorDetached(true);
    }

    function _showWorkspaceSidebarItem() {
        if (!_navItem) _navItem = _findWorkspaceNavItem();
        if (_navItem && _navItem.dataset.mmHidden === '1') {
            _navItem.style.removeProperty('display');
            _navItem.classList.add('sidebar-item-visible');
            delete _navItem.dataset.mmHidden;
        }
        if (window.SidebarGUIManager) window.SidebarGUIManager.setMultimonitorDetached(false);
    }

    // ─── Indicador en header ─────────────────────────────────────────────────

    function _createIndicator(screenLabel) {
        document.getElementById('mm-indicator')?.remove();
        const ind = document.createElement('div');
        ind.id = 'mm-indicator';
        ind.title = 'WorkSpace activo en ' + screenLabel;
        ind.innerHTML =
            '<i class="fas fa-desktop"></i>' +
            '<span class="mm-ind-label">WorkSpace en ' + _escapeHtml(screenLabel) + '</span>' +
            '<button class="mm-ind-focus" title="Traer al frente"><i class="fas fa-external-link-alt"></i></button>' +
            '<button class="mm-ind-close" title="Cerrar WorkSpace"><i class="fas fa-times"></i></button>';
        ind.querySelector('.mm-ind-focus').addEventListener('click', e => { e.stopPropagation(); _focusWorkspace(); });
        ind.querySelector('.mm-ind-close').addEventListener('click', e => { e.stopPropagation(); _closeWorkspace(); });
        _indicator = ind;
        const topbar = document.querySelector('.topbar, .navbar, header, .main-header, .sidebar-header');
        (topbar || document.body).appendChild(ind);
    }

    function _removeIndicator() {
        _indicator?.remove();
        _indicator = null;
        document.getElementById('mm-indicator')?.remove();
    }

    // ─── Ventana WorkSpace ────────────────────────────────────────────────────

    /**
     * Abre workspace en una nueva ventana y la posiciona en el monitor elegido.
     *
     * Estrategia de posicionamiento en orden de confiabilidad:
     * 1. window.open con features left/top (funciona si el permiso window-management está activo)
     * 2. moveTo() tras carga (backup — requiere mismo permiso)
     * 3. Ventana abre en pantalla actual: el usuario la arrastra manualmente
     */
    function _openWorkspace(screenInfo) {
        // Si el popup ya está abierto, solo moverlo — no volver a navegar (evita reload)
        if (_workspaceWin && !_workspaceWin.closed) {
            _moveWorkspaceTo(screenInfo);
            return;
        }

        const label  = screenInfo ? screenInfo.label  : 'Monitor 2';
        const left   = screenInfo ? Math.round(screenInfo.left)   : (window.screen.width + 50);
        const top    = screenInfo ? Math.round(screenInfo.top)    : 0;
        const width  = screenInfo ? Math.round(screenInfo.width)  : window.screen.availWidth;
        const height = screenInfo ? Math.round(screenInfo.height) : window.screen.availHeight;

        // Abrir primero con coordenadas en el features string.
        // Si el browser respeta las coordenadas cross-screen, quedará posicionada.
        const features = [
            'left='   + left,
            'top='    + top,
            'width='  + width,
            'height=' + height,
            'toolbar=no', 'menubar=no', 'status=no',
            'scrollbars=yes', 'resizable=yes'
        ].join(',');

        // Abre workspace con UI completa (sin mm_mode=detached).
        // Al ser un popup desde el inicio, moveTo() lo mueve sin recargar.
        _workspaceWin = window.open(WORKSPACE_URL, WIN_NAME, features);

        if (!_workspaceWin) {
            alert('El navegador bloqueó la apertura de la ventana.\nPermitir ventanas emergentes para este sitio e intentar de nuevo.');
            return;
        }

        _saveWorkspaceScreen(left, top, width, height);
        _setState(true, label);
        _hideWorkspaceSidebarItem();
        _createIndicator(label);
        _updateTriggerState();
        _watchWindowClose();

        // Intentar mover via moveTo() en múltiples momentos.
        // Chrome con permiso window-management: esto mueve la ventana al monitor correcto.
        // Sin permiso: moveTo puede quedar restringido a la pantalla actual — se muestra aviso.
        const attemptMove = () => {
            if (!_workspaceWin || _workspaceWin.closed) return;
            try {
                _workspaceWin.moveTo(left, top);
                _workspaceWin.resizeTo(width, height);
            } catch (_) {}
        };

        // Intentos escalonados para cubrir diferentes velocidades de carga
        setTimeout(attemptMove, 300);
        setTimeout(attemptMove, 800);
        setTimeout(attemptMove, 1800);

        // Cuando workspace cargue completamente, intentar una vez más y notificar
        _workspaceWin.addEventListener('load', () => {
            attemptMove();
            _channel && _channel.postMessage({ type: 'mm-host-connected', label });
        }, { once: true });
    }

    function _tryMoveWindow(win, left, top, width, height) {
        try { win.moveTo(left, top); win.resizeTo(width, height); } catch (_) {}
    }

    function _focusWorkspace() {
        if (_workspaceWin && !_workspaceWin.closed) _workspaceWin.focus();
    }

    function _closeWorkspace() {
        if (_workspaceWin && !_workspaceWin.closed) _workspaceWin.close();
        _onWorkspaceClosed();
    }

    function _onWorkspaceClosed() {
        _workspaceWin = null;
        _isDetached   = false;
        _setState(false);
        try { localStorage.removeItem('mm_workspace_screen'); } catch(_) {}
        _removeIndicator();
        _showWorkspaceSidebarItem();
        _updateTriggerState();
    }

    function _watchWindowClose() {
        const interval = setInterval(() => {
            if (!_workspaceWin || _workspaceWin.closed) {
                clearInterval(interval);
                _onWorkspaceClosed();
            }
        }, 1500);
    }

    // ─── Modal selector de monitor ────────────────────────────────────────────

    /**
     * @param {string} mode  'open'  → abre nueva ventana workspace en el monitor elegido
     *                       'move'  → mueve la ventana workspace ya abierta (_workspaceWin)
     */
    async function _showMonitorPicker(mode) {
        mode = mode || 'open';

        // Solicitar permiso Window Management antes de mostrar el picker
        const { screens, source, apiCount } = await _getScreensInfo();

        document.getElementById('mm-picker-modal')?.remove();

        const titleText = mode === 'move'
            ? 'Mover WorkSpace a otro monitor'
            : 'Abrir WorkSpace en otro monitor';
        const hintText  = mode === 'move'
            ? 'Seleccioná el monitor al que mover la ventana de WorkSpace.'
            : 'Seleccioná el monitor donde abrir WorkSpace. El dashboard permanece aquí.';

        // Mensaje contextual según la fuente de datos
        let warningHtml = '';
        if (source === 'api-partial') {
            warningHtml = `<div class="mm-api-warning">
                <i class="fas fa-shield-alt me-1"></i>
                <span><strong>Brave / modo privacidad:</strong> este browser reporta ${apiCount} monitor(es) 
                pero protege sus dimensiones. Las posiciones son correctas; el tamaño es estimado 
                en base al monitor actual.</span>
            </div>`;
        } else if (source === 'estimated') {
            warningHtml = `<div class="mm-api-warning">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <span>Tu browser no comparte datos de pantallas secundarias. 
                Las posiciones son estimadas asumiendo monitores en fila horizontal. 
                Si la ventana no abre en el monitor correcto, arrastrala o probá otra opción.</span>
            </div>`;
        }

        const modal = document.createElement('div');
        modal.id = 'mm-picker-modal';
        modal.innerHTML = `
            <div class="mm-backdrop"></div>
            <div class="mm-dialog" role="dialog" aria-modal="true" aria-labelledby="mm-dialog-title">
                <div class="mm-dialog-header">
                    <i class="fas fa-desktop me-2"></i>
                    <span id="mm-dialog-title">${_escapeHtml(titleText)}</span>
                    <button class="mm-dialog-close" title="Cancelar" id="mm-close-btn"><i class="fas fa-times"></i></button>
                </div>
                <div class="mm-dialog-body">
                    ${warningHtml}
                    <p class="mm-dialog-hint">${_escapeHtml(hintText)}</p>
                    <div class="mm-screens-grid" id="mm-screens-grid"></div>
                </div>
            </div>`;

        const grid = modal.querySelector('#mm-screens-grid');
        screens.forEach((screen) => {
            const isEstimated  = !!screen.estimated;
            const isDimEst     = !!screen.dimEstimated;

            const card = document.createElement('button');
            card.className = 'mm-screen-card'
                + (screen.isPrimary ? ' mm-screen-primary'   : '')
                + (isEstimated      ? ' mm-screen-estimated' : '');
            card.type = 'button';

            // Texto de dimensiones: real > estimado desde dims Brave > estimado
            let dimsText;
            if (screen.fromApi && !isDimEst) {
                dimsText = `${screen.width} × ${screen.height}`;
            } else if (screen.fromApi && isDimEst) {
                dimsText = `~${screen.width} × ~${screen.height}`;
            } else {
                dimsText = 'posición estimada';
            }

            // Badge
            let badgeHtml = '';
            if (screen.isPrimary)  badgeHtml += '<span class="mm-screen-badge">Este monitor</span>';
            if (isEstimated)       badgeHtml += '<span class="mm-screen-badge mm-screen-badge-warn">estimado</span>';
            if (isDimEst && !isEstimated) badgeHtml += '<span class="mm-screen-badge mm-screen-badge-warn">dim. aprox.</span>';

            card.innerHTML = `
                <i class="fas fa-desktop mm-screen-icon"></i>
                <span class="mm-screen-label">${_escapeHtml(screen.label)}</span>
                <span class="mm-screen-dims">${_escapeHtml(dimsText)}</span>
                ${badgeHtml}`;

            card.addEventListener('click', () => {
                modal.remove();
                if (mode === 'move') {
                    _moveWorkspaceTo(screen);
                } else {
                    // Siempre abrir como popup (permite moveTo() sin reload)
                    if (screen.isPrimary) {
                        // "Este monitor" → popup en el monitor actual del dashboard
                        _openWorkspaceOnCurrentMonitor();
                    } else {
                        _openWorkspace(screen);
                    }
                }
            });

            grid.appendChild(card);
        });

        modal.querySelector('#mm-close-btn').addEventListener('click', () => modal.remove());
        modal.querySelector('.mm-backdrop').addEventListener('click', () => modal.remove());
        document.body.appendChild(modal);
    }

    /**
     * Mueve la ventana workspace ya abierta a otro monitor usando moveTo/resizeTo.
     */
    function _moveWorkspaceTo(screenInfo) {
        if (!_workspaceWin || _workspaceWin.closed) {
            // Si no tenemos referencia a la ventana, informar
            alert('No se encontró una ventana de WorkSpace abierta para mover.\nUsá el botón para abrir WorkSpace en otro monitor.');
            return;
        }
        const left   = Math.round(screenInfo.left);
        const top    = Math.round(screenInfo.top);
        const width  = Math.round(screenInfo.width);
        const height = Math.round(screenInfo.height);
        _tryMoveWindow(_workspaceWin, left, top, width, height);
        _workspaceWin.focus();
        _saveWorkspaceScreen(left, top, width, height);
        // Actualizar label en indicador
        _removeIndicator();
        _createIndicator(screenInfo.label);
        _setState(true, screenInfo.label);
    }

    // ─── Apertura desde enlace del sidebar ───────────────────────────────────

    /**
     * Abre workspace como popup en el monitor donde está el dashboard.
     * Sin mm_mode=detached → workspace con su UI completa (sidebar propio, paneles, etc.).
     * Al ser popup desde el principio, moveTo() lo mueve a otro monitor SIN recargar.
     */
    function _openWorkspaceOnCurrentMonitor() {
        if (_workspaceWin && !_workspaceWin.closed) {
            _workspaceWin.focus();
            return;
        }

        // Posición del monitor actual (donde está el dashboard)
        const sl = (typeof window.screen.availLeft !== 'undefined') ? window.screen.availLeft : 0;
        const st = (typeof window.screen.availTop  !== 'undefined') ? window.screen.availTop  : 0;
        const sw = window.screen.availWidth;
        const sh = window.screen.availHeight;

        const features = [
            'left=' + sl, 'top=' + st,
            'width=' + sw, 'height=' + sh,
            'toolbar=no', 'menubar=no', 'status=no',
            'scrollbars=yes', 'resizable=yes'
        ].join(',');

        _workspaceWin = window.open(WORKSPACE_URL, WIN_NAME, features);

        if (!_workspaceWin) {
            // Popup bloqueado → fallback: navegar al workspace clásico
            window.location.href = WORKSPACE_URL;
            return;
        }

        _saveWorkspaceScreen(sl, st, sw, sh);
        _setState(true, 'Monitor actual');
        _hideWorkspaceSidebarItem();
        _createIndicator('Monitor actual');
        _updateTriggerState();
        _watchWindowClose();
    }

    /**
     * Intercepta el clic en el enlace "WorkSpace" del sidebar del dashboard.
     * Solo se activa para usuarios con el permiso feature_multimonitor
     * (este script solo se carga cuando ese permiso está activo).
     * Abre workspace como popup en vez de navegar a app-container.html,
     * habilitando el movimiento sin recarga entre monitores.
     */
    function _interceptWorkspaceLink() {
        const selectors = [
            'a[href*="app-container.html?section=workspace"]',
            'a[data-section="workspace"]',
            'a[href*="workspace.html"]'
        ];
        let link = null;
        for (const sel of selectors) {
            link = document.querySelector(sel);
            if (link) break;
        }
        if (!link || link.dataset.mmIntercepted) return;
        link.dataset.mmIntercepted = '1';

        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            _openWorkspaceOnCurrentMonitor();
        });
    }

    // ─── Botón trigger en sidebar ─────────────────────────────────────────────

    function _injectTriggerButton() {
        if (document.getElementById('mm-trigger-btn')) return;

        const btn = document.createElement('button');
        btn.id   = 'mm-trigger-btn';
        btn.type = 'button';
        btn.title = 'Abrir WorkSpace en otro monitor';
        btn.innerHTML = '<i class="fas fa-desktop"></i>';

        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            if (_isDetached) {
                // Workspace ya abierto en otra ventana: ofrecer focus o mover
                await _showMonitorPicker('move');
                return;
            }
            // Abrir en otro monitor
            await _showMonitorPicker('open');
        });

        // Inyectar como HERMANO del <a>, fuera del anchor, dentro del .nav-item
        const wsSelectors = [
            'a[href*="app-container.html?section=workspace"]',
            'a[data-section="workspace"]',
            'a[href*="workspace.html"]'
        ];
        for (const sel of wsSelectors) {
            const wsLink = document.querySelector(sel);
            if (wsLink) {
                const navItem = wsLink.closest('.nav-item') || wsLink.parentElement;
                if (navItem) {
                    navItem.style.position = 'relative';
                    btn.classList.add('mm-trigger-sibling');
                    navItem.appendChild(btn);
                    return;
                }
            }
        }

        // Fallback: sidebar-header
        const sidebarHeader = document.querySelector('.sidebar-header .d-flex');
        if (sidebarHeader) {
            btn.classList.add('mm-trigger-header');
            sidebarHeader.appendChild(btn);
            return;
        }

        // Último recurso: flotante
        btn.classList.add('mm-trigger-floating');
        document.body.appendChild(btn);
    }

    function _updateTriggerState() {
        const btn = document.getElementById('mm-trigger-btn');
        if (!btn) return;
        if (_isDetached) {
            btn.title = 'WorkSpace activo en monitor secundario — clic para mover o traer al frente';
            btn.style.color = '#4fc3f7';
        } else {
            btn.title = 'Abrir WorkSpace en otro monitor';
            btn.style.color = '';
        }
    }

    // ─── CSS embebido ─────────────────────────────────────────────────────────

    function _injectStyles() {
        if (document.getElementById('mm-styles')) return;
        const style = document.createElement('style');
        style.id = 'mm-styles';
        style.textContent = `
/* ═══ Multi-Monitor Manager — Estilos ═══ */

/* Botón como hermano del <a>, posicionado sobre el .nav-item */
#mm-trigger-btn {
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.4);
    border-radius: 4px;
    padding: 4px 7px;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    line-height: 1;
    vertical-align: middle;
}
#mm-trigger-btn:hover { color: #4fc3f7; background: rgba(79,195,247,0.12); }

#mm-trigger-btn.mm-trigger-sibling {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    pointer-events: all;
}
/* El nav-link deja espacio para el botón sibling */
.nav-item:has(#mm-trigger-btn.mm-trigger-sibling) > .nav-link {
    padding-right: 36px;
}
#mm-trigger-btn.mm-trigger-header {
    border: 1px solid rgba(255,255,255,0.2);
    padding: 3px 7px;
    color: rgba(255,255,255,0.6);
    border-radius: 5px;
}
#mm-trigger-btn.mm-trigger-floating {
    position: fixed;
    bottom: 80px; right: 20px;
    z-index: 1050;
    padding: 10px 12px; font-size: 16px;
    background: #1c1c2e;
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 50%;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    color: rgba(255,255,255,0.8);
}

/* Indicador de workspace activo en monitor secundario */
#mm-indicator {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(10,132,255,0.15);
    border: 1px solid rgba(10,132,255,0.4);
    border-radius: 20px; padding: 3px 10px 3px 8px;
    font-size: 12px; color: #4fc3f7;
    margin-left: 10px; white-space: nowrap;
}
#mm-indicator .fa-desktop { color: #4fc3f7; font-size: 13px; }
.mm-ind-label { font-weight: 500; }
.mm-ind-focus, .mm-ind-close {
    background: transparent; border: none; color: #4fc3f7;
    cursor: pointer; padding: 2px 4px; border-radius: 4px;
    font-size: 11px; transition: background 0.1s; line-height: 1;
}
.mm-ind-focus:hover { background: rgba(10,132,255,0.2); }
.mm-ind-close:hover { background: rgba(255,50,50,0.2); color: #ff6b6b; }

/* Modal selector de monitor */
#mm-picker-modal {
    position: fixed; inset: 0; z-index: 10500;
    display: flex; align-items: center; justify-content: center;
}
.mm-backdrop {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.65); backdrop-filter: blur(3px);
}
.mm-dialog {
    position: relative; background: #1c1c2e;
    border: 1px solid rgba(255,255,255,0.12); border-radius: 14px;
    width: min(540px, 92vw); box-shadow: 0 20px 60px rgba(0,0,0,0.6); overflow: hidden;
}
.mm-dialog-header {
    display: flex; align-items: center; padding: 16px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    color: #fff; font-size: 15px; font-weight: 600;
}
.mm-dialog-close {
    margin-left: auto; background: transparent; border: none;
    color: rgba(255,255,255,0.5); cursor: pointer;
    padding: 4px 6px; border-radius: 6px; font-size: 14px;
    transition: background 0.1s, color 0.1s;
}
.mm-dialog-close:hover { background: rgba(255,255,255,0.1); color: #fff; }
.mm-dialog-body { padding: 20px; }
.mm-dialog-hint { font-size: 13px; color: rgba(255,255,255,0.55); margin-bottom: 16px; }
.mm-api-warning {
    background: rgba(255,193,7,0.1); border: 1px solid rgba(255,193,7,0.3);
    border-radius: 8px; padding: 10px 14px; font-size: 12px;
    color: #ffc107; margin-bottom: 14px; display: flex; gap: 8px; align-items: flex-start;
}
.mm-screens-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;
}
.mm-screen-card {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    padding: 20px 14px;
    background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; cursor: pointer;
    transition: background 0.15s, border-color 0.15s, transform 0.1s;
    color: #fff; text-align: center; position: relative;
}
.mm-screen-card:hover { background: rgba(10,132,255,0.12); border-color: rgba(10,132,255,0.5); transform: translateY(-2px); }
.mm-screen-card.mm-screen-primary { border-color: rgba(255,255,255,0.08); }
.mm-screen-card.mm-screen-primary:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.18); transform: none; }
.mm-screen-card.mm-screen-estimated { border-color: rgba(255,193,7,0.2); }
.mm-screen-card.mm-screen-estimated:hover { background: rgba(255,193,7,0.08); border-color: rgba(255,193,7,0.4); }
.mm-screen-card.mm-screen-estimated .mm-screen-icon { color: rgba(255,193,7,0.7); }
.mm-screen-badge-warn { background: rgba(255,193,7,0.15) !important; color: #ffc107 !important; top: auto !important; right: auto !important; position: static !important; align-self: center; }
.mm-screen-icon { font-size: 28px; color: #4fc3f7; }
.mm-screen-card.mm-screen-primary .mm-screen-icon { color: rgba(255,255,255,0.35); }
.mm-screen-label { font-size: 13px; font-weight: 600; }
.mm-screen-dims  { font-size: 11px; color: rgba(255,255,255,0.45); }
.mm-screen-badge {
    position: absolute; top: 6px; right: 8px;
    font-size: 10px; background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.5); border-radius: 10px; padding: 1px 7px;
}
        `;
        document.head.appendChild(style);
    }

    // ─── BroadcastChannel ─────────────────────────────────────────────────────

    function _initChannel() {
        if (!('BroadcastChannel' in window)) return;
        _channel = new BroadcastChannel(CHANNEL_NAME);
        _channel.onmessage = (ev) => {
            const type  = ev.data && ev.data.type;
            const label = ev.data && ev.data.label;
            const screen = ev.data && ev.data.screen;

            if (type === 'mm-workspace-closed') {
                _onWorkspaceClosed();

            } else if (type === 'mm-workspace-moved' && label) {
                // Workspace notificó que se movió — actualizar indicador en dashboard
                _removeIndicator();
                _createIndicator(label);
                _setState(true, label);

            } else if (type === 'mm-move-request' && screen) {
                // Workspace pide que el dashboard lo mueva a otro monitor.
                // El dashboard tiene el permiso window-management y la referencia _workspaceWin.
                const sl = Math.round(screen.left);
                const st = Math.round(screen.top);
                const sw = Math.round(screen.width);
                const sh = Math.round(screen.height);
                if (_workspaceWin && !_workspaceWin.closed) {
                    _tryMoveWindow(_workspaceWin, sl, st, sw, sh);
                    _workspaceWin.focus();
                    _saveWorkspaceScreen(sl, st, sw, sh);
                    _removeIndicator();
                    _createIndicator(screen.label || label || 'Monitor secundario');
                    _setState(true, screen.label || label || 'Monitor secundario');
                } else {
                    // Dashboard perdió la referencia — guardar coordenadas de todas formas
                    // para que mm-study-redirect.js las pueda usar
                    _saveWorkspaceScreen(sl, st, sw, sh);
                    _setState(true, screen.label || label || 'Monitor secundario');
                }
            }
        };
    }

    // ─── API pública ──────────────────────────────────────────────────────────

    /**
     * Redirige un estudio al popup workspace si ya está abierto (detached).
     * NO abre un popup nuevo — eso es responsabilidad del usuario via "Mover a otro monitor".
     * Si no hay popup activo, retorna false y openInWorkspace() usa el flujo normal.
     *
     * @param {string} url  URL de workspace con parámetros del estudio
     * @returns {boolean}   true si hay popup y se le envió el estudio, false si no hay popup
     */
    function loadStudyInPopup(url) {
        // ── Caso A: referencia directa al popup ──────────────────────────────────
        // Usamos postMessage en vez de location.href directo para que workspace.html
        // pueda preservar mm_mode=detached y gestionar el flag de navegación.
        if (_workspaceWin && !_workspaceWin.closed) {
            try {
                if (window.StudyQueue) {
                    StudyQueue.setCurrentStudyKeyFromUrl(url);
                }
                _workspaceWin.postMessage({ type: 'mm-load-study', url: url }, '*');
                _workspaceWin.focus();
                return true;
            } catch (_) {}
        }

        // ── Caso B: estado guardado (popup abierto desde workspace, no desde dashboard) ──
        // Ocurre cuando el usuario abrió el popup via "Mover a otro monitor" dentro de
        // workspace.html (app-container), donde el dashboard no tenía referencia directa.
        if (_isDetached) {
            try {
                if (window.StudyQueue) {
                    StudyQueue.setCurrentStudyKeyFromUrl(url);
                }
                if (_channel) {
                    _channel.postMessage({ type: 'mm-load-study', url: url });
                    return true;
                }
            } catch (_) {}
        }

        // Sin popup activo → dejar que openInWorkspace() use su flujo normal
        return false;
    }

    async function init() {
        _injectStyles();
        _initChannel();

        // El permiso window-management se solicita solo cuando el usuario
        // abre el picker (user gesture). No hacer pre-fetch — Chrome
        // puede rechazarlo o cachearlo incorrectamente sin gesto del usuario.

        // Revisar estado persistido
        const stored = _getStoredState();
        if (stored && stored.open) {
            _isDetached = true;
            _hideWorkspaceSidebarItem();
            _createIndicator(stored.screen || 'Monitor secundario');

            // Verificar si la ventana realmente sigue viva con ping/pong
            let confirmed = false;
            if (_channel) {
                const handler = (ev) => {
                    if (ev.data && ev.data.type === 'mm-workspace-pong') {
                        confirmed = true;
                        _channel.removeEventListener('message', handler);
                        // Workspace sigue vivo → activar interceptor de estudios
                        _startInterceptingOpens();
                    }
                };
                _channel.addEventListener('message', handler);
                _channel.postMessage({ type: 'mm-workspace-ping' });
                setTimeout(() => {
                    if (!confirmed) {
                        _channel.removeEventListener('message', handler);
                        _onWorkspaceClosed();
                    }
                }, 3000);
            } else {
                setTimeout(() => { if (_isDetached && !_workspaceWin) _onWorkspaceClosed(); }, 3000);
            }
        }

        // Cerrar workspace cuando el dashboard se cierra o hace logout
        // pagehide es más confiable que beforeunload para SPA/navegación interna
        window.addEventListener('pagehide', function() {
            if (!_isDetached) return;
            try { _channel && _channel.postMessage({ type: 'mm-dashboard-closing' }); } catch(_) {}
            // Intentar cerrar directamente si tenemos referencia al popup
            try { _workspaceWin && !_workspaceWin.closed && _workspaceWin.close(); } catch(_) {}
        });
        // beforeunload como respaldo (cubre logout que navega a otra página)
        window.addEventListener('beforeunload', function() {
            if (!_isDetached) return;
            try { _channel && _channel.postMessage({ type: 'mm-dashboard-closing' }); } catch(_) {}
        });

        // Inyectar botón cuando el sidebar esté disponible
        let _attempts = 0;
        const ready = () => {
            _attempts++;
            const found = [
                'a[href*="app-container.html?section=workspace"]',
                'a[data-section="workspace"]',
                'a[href*="workspace.html"]',
                '.sidebar-header'
            ].some(sel => document.querySelector(sel));
            if (found) {
                _injectTriggerButton();
                _interceptWorkspaceLink();   // popup en vez de navegación al iframe
                _updateTriggerState();
            } else if (_attempts < 30) {
                setTimeout(ready, 300);
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => setTimeout(ready, 600));
        } else {
            setTimeout(ready, 600);
        }
    }

    return { init, loadStudyInPopup };

})();

window.MultiMonitorManager = MultiMonitorManager;
