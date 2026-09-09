/**
 * mm-study-redirect.js
 * Interceptor liviano de window.open para páginas secundarias del sistema
 * (estudios-manager, pacs-manager, etc.).
 *
 * Cuando el workspace está en modo multi-monitor (estado guardado en localStorage),
 * redirige window.open(_blank) al monitor donde está workspace abriendo la nueva
 * ventana con coordenadas explícitas del monitor destino.
 *
 * - No tiene dependencias externas.
 * - No-op si el workspace no está en modo multi-monitor.
 * - Debe cargarse antes de cualquier botón/menú que llame a window.open.
 */
(function () {
    'use strict';

    const LS_STATE   = 'mm_workspace_state';
    const LS_SCREEN  = 'mm_workspace_screen';
    const CHANNEL    = 'mm-workspace-state';

    /** Devuelve true si hay un workspace popup activo (según localStorage). */
    function _isMmActive() {
        try {
            const raw = localStorage.getItem(LS_STATE);
            if (!raw) return false;
            const state = JSON.parse(raw);
            if (!state || !state.open) return false;
            // Descartar estados muy viejos (más de 8 horas sin confirmación)
            if (state.ts && Date.now() - state.ts > 8 * 3600 * 1000) return false;
            return true;
        } catch (_) {
            return false;
        }
    }

    /**
     * Lee las coordenadas del monitor del workspace desde localStorage.
     * Devuelve un features string para window.open, o null si no hay datos.
     */
    function _workspaceFeatures() {
        try {
            const raw = localStorage.getItem(LS_SCREEN);
            if (!raw) return null;
            const sc = JSON.parse(raw);
            // Descartar coordenadas muy viejas (más de 8 horas)
            if (sc.ts && Date.now() - sc.ts > 8 * 3600 * 1000) return null;
            if (sc.left === undefined || sc.top === undefined) return null;
            const w = sc.width  || window.screen.availWidth;
            const h = sc.height || window.screen.availHeight;
            return 'left=' + sc.left + ',top=' + sc.top + ',width=' + w + ',height=' + h +
                   ',toolbar=no,menubar=no,status=no,scrollbars=yes,resizable=yes';
        } catch (_) {
            return null;
        }
    }

    /**
     * Envía la URL al workspace popup vía BroadcastChannel (fallback).
     * Workspace lo abre desde su propio contexto (mismo monitor).
     */
    function _redirectViaBroadcast(url) {
        if (!('BroadcastChannel' in window)) return false;
        try {
            const ch = new BroadcastChannel(CHANNEL);
            ch.postMessage({ type: 'mm-open-url', url: url });
            // Cerrar el canal tras un breve delay para asegurar entrega
            setTimeout(function () { ch.close(); }, 500);
            return true;
        } catch (_) {
            return false;
        }
    }

    // Guardar referencia original antes de parchear
    const _origOpen = window.open.bind(window);

    window.open = function (url, target, features) {
        // Solo interceptar target='_blank' con URL válida y mm activo
        if (target === '_blank' && url && _isMmActive()) {
            // Estrategia 1: abrir directamente en el monitor del workspace usando
            // coordenadas guardadas en localStorage.
            const feat = _workspaceFeatures();
            if (feat) {
                try {
                    const win = _origOpen(url, '_blank', feat);
                    if (win) return win;
                } catch (_) {}
            }

            // Estrategia 2: pedir al popup workspace que lo abra desde su contexto
            const sent = _redirectViaBroadcast(url);
            if (sent) return null;
        }
        return _origOpen(url, target, features);
    };

})();
