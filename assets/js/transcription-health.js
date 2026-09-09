/**
 * Helper: health del servidor de transcripción (proxy portal → Whisper).
 * URL real viene de Configuración → AI Informes (ai_config), nunca hardcodeada aquí.
 */
(function (global) {
    'use strict';

    function resolveAiInformesUrl() {
        const path = global.location.pathname || '';
        if (path.includes('/components/')) {
            return '../api/ai-informes.php';
        }
        return 'api/ai-informes.php';
    }

    function getToken() {
        if (typeof global.getAuthToken === 'function') {
            return global.getAuthToken();
        }
        try {
            return localStorage.getItem('authToken')
                || localStorage.getItem('token')
                || sessionStorage.getItem('authToken')
                || '';
        } catch (e) {
            return '';
        }
    }

    /**
     * @returns {Promise<{ready:boolean,status:string,allow_enqueue:boolean,degraded:boolean,message:string}>}
     */
    async function fetchTranscriptionHealth(options) {
        const opts = options || {};
        const token = opts.token || getToken();
        const url = (opts.apiBase || resolveAiInformesUrl()) + '?action=transcription-health';
        const headers = { Accept: 'application/json' };
        if (token) {
            headers.Authorization = 'Bearer ' + token;
        }

        let response;
        try {
            response = await fetch(url, {
                method: 'GET',
                headers,
                signal: opts.signal || (global.AbortSignal && AbortSignal.timeout
                    ? AbortSignal.timeout(opts.timeoutMs || 5000)
                    : undefined),
            });
        } catch (err) {
            return {
                ready: false,
                status: 'down',
                allow_enqueue: false,
                degraded: false,
                message: 'No se pudo consultar el estado del transcriptor: ' + (err.message || err),
                success: false,
            };
        }

        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = {};
        }

        const status = (data.status || (response.ok ? 'ok' : 'down')).toLowerCase();
        const allow = data.allow_enqueue === true
            || (data.ready === true && (status === 'ok' || status === 'degraded'));

        return {
            success: data.success !== false,
            ready: !!data.ready,
            status,
            allow_enqueue: allow,
            degraded: status === 'degraded' || !!data.degraded,
            message: data.message || (allow
                ? (status === 'degraded' ? 'Transcriptor degradado' : 'Transcriptor OK')
                : 'Transcriptor no disponible'),
            http_code: data.http_code || response.status,
            raw: data.raw || null,
        };
    }

    /**
     * Si down → lanza Error. Si degraded → devuelve health (caller puede avisar).
     */
    async function assertCanEnqueueTranscription(options) {
        const health = await fetchTranscriptionHealth(options);
        if (!health.allow_enqueue) {
            const err = new Error(health.message || 'El servidor de transcripción no está disponible');
            err.transcriptionHealth = health;
            throw err;
        }
        return health;
    }

    global.TranscriptionHealth = {
        fetch: fetchTranscriptionHealth,
        assertCanEnqueue: assertCanEnqueueTranscription,
    };
})(typeof window !== 'undefined' ? window : this);
