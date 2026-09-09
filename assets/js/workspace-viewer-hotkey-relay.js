/**
 * Reenvío de atajos de teclado al workspace (postMessage).
 *
 * Uso en webportal.iddse.com.ar (o el host del visor), cuando el visor se embebe
 * en un iframe en https://plataforma.iddse.com.ar/components/workspace.html
 *
 * Incluir ANTES del cierre de </body> (o al inicio del visor):
 *   <script
 *     src="https://plataforma.iddse.com.ar/assets/js/workspace-viewer-hotkey-relay.js"
 *     data-workspace-parent="https://plataforma.iddse.com.ar"
 *     defer></script>
 *
 * O con parámetro en la URL del visor: &workspaceParent=https%3A%2F%2Fplataforma.iddse.com.ar
 *
 * Solo reenvía combinaciones con Ctrl o Cmd (Meta), y no cuando el foco está en input/textarea.
 */
(function () {
    'use strict';

    function resolveParentOrigin() {
        try {
            var cur = document.currentScript;
            if (cur && cur.getAttribute('data-workspace-parent')) {
                return cur.getAttribute('data-workspace-parent').trim();
            }
        } catch (e) {}
        try {
            var m = window.location.search.match(/(?:^|[?&])workspaceParent=([^&]+)/);
            if (m) return decodeURIComponent(m[1]).trim();
        } catch (e2) {}
        var meta = document.querySelector('meta[name="workspace-parent"][content]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content').trim();
        }
        return 'https://plataforma.iddse.com.ar';
    }

    var PARENT_ORIGIN = resolveParentOrigin();

    if (!window.parent || window.parent === window) {
        return;
    }

    function isTypingContext() {
        var el = document.activeElement;
        if (!el) return false;
        var tag = el.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (el.isContentEditable) return true;
        if (el.closest && el.closest('[contenteditable="true"]')) return true;
        try {
            while (el && el.shadowRoot && el.shadowRoot.activeElement) {
                el = el.shadowRoot.activeElement;
                if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') return true;
            }
        } catch (e) {}
        return false;
    }

    function relay(e) {
        if (isTypingContext()) return;
        if (!e.ctrlKey && !e.metaKey) return;
        try {
            window.parent.postMessage({
                type: 'workspaceAudioHotkeyFromViewer',
                key: e.key,
                ctrlKey: !!e.ctrlKey,
                metaKey: !!e.metaKey,
                shiftKey: !!e.shiftKey,
                altKey: !!e.altKey
            }, PARENT_ORIGIN);
        } catch (err) {}
    }

    window.addEventListener('keydown', relay, true);
})();
