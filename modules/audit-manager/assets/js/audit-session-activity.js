/**
 * Acumula en servidor el tiempo de sesión con uso real: pestaña visible + interacción reciente.
 * Requiere columnas sesiones.active_seconds / active_tick_at (install.php del audit-manager).
 *
 * Varios marcos (p. ej. app-container + iframe workspace): BroadcastChannel sincroniza la última
 * actividad; solo window.top envía POST para no duplicar segundos en el servidor.
 */
(function () {
    if (window.__auditSessionActivityRelay) {
        return;
    }
    window.__auditSessionActivityRelay = true;

    var IDLE_MS = 90000;
    var HEARTBEAT_MS = 45000;
    var THROTTLE_MS = 1000;
    var lastActivity = Date.now();
    var BC_NAME = 'audit_session_activity_v1';
    var bc = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(BC_NAME) : null;

    function apiBase() {
        var path = window.location.pathname || '';
        if (path.indexOf('/components/') !== -1) {
            return '../modules/audit-manager/api/';
        }
        if (path.indexOf('/modules/') !== -1) {
            return 'audit-manager/api/';
        }
        return 'modules/audit-manager/api/';
    }

    function throttle(fn, ms) {
        var t = 0;
        return function () {
            var n = Date.now();
            if (n - t < ms) {
                return;
            }
            t = n;
            fn();
        };
    }

    function bumpActivity() {
        lastActivity = Date.now();
        if (bc) {
            try {
                bc.postMessage({ t: lastActivity });
            } catch (e) {}
        }
    }

    if (bc) {
        bc.onmessage = function (ev) {
            if (ev.data && typeof ev.data.t === 'number') {
                lastActivity = Math.max(lastActivity, ev.data.t);
            }
        };
    }

    var onInteract = throttle(bumpActivity, THROTTLE_MS);
    ['pointerdown', 'click', 'keydown', 'scroll'].forEach(function (ev) {
        document.addEventListener(ev, onInteract, true);
    });

    function isForegroundTab() {
        return document.visibilityState !== 'hidden';
    }

    function shouldSend() {
        if (!isForegroundTab()) {
            return false;
        }
        return Date.now() - lastActivity < IDLE_MS;
    }

    function tick() {
        if (window.top !== window.self) {
            return;
        }
        if (!shouldSend()) {
            return;
        }
        fetch(apiBase() + 'activity-heartbeat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        }).catch(function () {});
    }

    setInterval(tick, HEARTBEAT_MS);
    document.addEventListener('visibilitychange', function () {
        if (isForegroundTab()) {
            tick();
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tick);
    } else {
        tick();
    }
})();
