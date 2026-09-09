/**
 * Envía errores de JavaScript al módulo audit-manager (audit_manager_events vía record.php).
 * Requiere sesión válida (cookie). Límites locales para no saturar el servidor.
 *
 * action_key: client.runtime_error | client.unhandledrejection | client.console_error
 */
(function () {
    if (window.__auditClientErrorsStarted) {
        return;
    }
    window.__auditClientErrorsStarted = true;

    var MAX_STACK = 6000;
    var MAX_MSG = 800;
    var DEDUPE_MS = 120000;
    var MAX_PER_HOUR = 60;
    var hourStart = Date.now();
    var hourCount = 0;
    var lastSent = Object.create(null);

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

    function truncate(s, n) {
        if (s == null) {
            return '';
        }
        s = String(s);
        return s.length > n ? s.slice(0, n) + '\u2026' : s;
    }

    function fingerprint(msg, url, stack) {
        return truncate(msg, 200) + '|' + truncate(url, 200) + '|' + truncate(stack || '', 120);
    }

    function allowSend(fp) {
        var now = Date.now();
        if (now - hourStart > 3600000) {
            hourStart = now;
            hourCount = 0;
        }
        if (hourCount >= MAX_PER_HOUR) {
            return false;
        }
        var t = lastSent[fp];
        if (t && now - t < DEDUPE_MS) {
            return false;
        }
        lastSent[fp] = now;
        hourCount++;
        if (Object.keys(lastSent).length > 300) {
            lastSent = Object.create(null);
        }
        return true;
    }

    function send(actionKey, description, meta) {
        var fp = fingerprint(meta.message || '', meta.page_url || '', meta.stack || '');
        if (!allowSend(fp)) {
            return;
        }
        var body = JSON.stringify({
            action_key: actionKey,
            description: truncate(description, 500),
            metadata: meta,
        });
        fetch(apiBase() + 'record.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: body,
        }).catch(function () {});
    }

    window.addEventListener(
        'error',
        function (ev) {
            var err = ev.error;
            var message = err && err.message ? err.message : String(ev.message || 'error');
            var stack = err && err.stack ? err.stack : '';
            send('client.runtime_error', message, {
                message: truncate(message, MAX_MSG),
                stack: truncate(stack, MAX_STACK),
                page_url: String(window.location.href).slice(0, 512),
                source_url: ev.filename ? String(ev.filename).slice(0, 512) : '',
                lineno: ev.lineno,
                colno: ev.colno,
            });
        },
        true
    );

    window.addEventListener('unhandledrejection', function (ev) {
        var reason = ev.reason;
        var message = '';
        var stack = '';
        if (reason instanceof Error) {
            message = reason.message;
            stack = reason.stack || '';
        } else {
            message = String(reason);
        }
        send('client.unhandledrejection', message, {
            message: truncate(message, MAX_MSG),
            stack: truncate(stack, MAX_STACK),
            page_url: String(window.location.href).slice(0, 512),
        });
    });

    var origErr = console.error;
    console.error = function () {
        try {
            origErr.apply(console, arguments);
        } catch (e) {
            /* ignore */
        }
        var a0 = arguments[0];
        if (!(a0 instanceof Error)) {
            return;
        }
        send('client.console_error', a0.message, {
            message: truncate(a0.message, MAX_MSG),
            stack: truncate(a0.stack || '', MAX_STACK),
            page_url: String(window.location.href).slice(0, 512),
            via: 'console.error',
        });
    };
})();
