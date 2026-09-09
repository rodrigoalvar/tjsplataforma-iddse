/**
 * Muestreo ligero de RTT para audit_manager_events (connectivity.ping).
 * Incluir en dashboard u otras páginas si se desea telemetría global.
 * No hace nada si el usuario no está autenticado (las APIs responderán 401).
 */
(function () {
    var INTERVAL_MS = 5 * 60 * 1000;
    var STORAGE_KEY = 'audit_connectivity_last_ping';

    function apiBase() {
        var path = window.location.pathname || '';
        if (path.indexOf('/components/') !== -1) return '../modules/audit-manager/api/';
        if (path.indexOf('/modules/') !== -1) return 'audit-manager/api/';
        return 'modules/audit-manager/api/';
    }

    function shouldSkip() {
        try {
            var last = parseInt(sessionStorage.getItem(STORAGE_KEY) || '0', 10);
            if (Date.now() - last < INTERVAL_MS) return true;
        } catch (e) {}
        return false;
    }

    function mark() {
        try {
            sessionStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch (e) {}
    }

    async function pingOnce() {
        if (shouldSkip()) return;
        var base = apiBase();
        var t0 = performance.now();
        var res = await fetch(base + 'ping.php', { credentials: 'same-origin' });
        var t1 = performance.now();
        if (!res.ok) return;
        var rtt = Math.round(t1 - t0);
        var data = await res.json().catch(function () {
            return {};
        });
        mark();
        await fetch(base + 'record.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action_key: 'connectivity.ping',
                rtt_ms: rtt,
                client_duration_ms: rtt,
                metadata: { server_processing_ms: data.server_processing_ms },
            }),
        }).catch(function () {});
    }

    function start() {
        pingOnce().catch(function () {});
        setInterval(function () {
            pingOnce().catch(function () {});
        }, INTERVAL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
