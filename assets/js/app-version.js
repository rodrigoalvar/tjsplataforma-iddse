/**
 * Versión de la aplicación — sincronizar con version.json en cada deploy.
 * Usado para cache busting (?v=build) y utilidades en runtime.
 */
(function () {
    const APP_VERSION = Object.freeze({
        version: '1.4.1',
        build: '202609031000',
        released: '2026-09-02'
    });

    window.APP_VERSION = APP_VERSION;

    window.appendAppVersion = function (url) {
        if (!url || /^https?:\/\//i.test(url)) return url;
        if (/[?&]v=/.test(url)) {
            return url.replace(/([?&])v=[^&]*/, '$1v=' + APP_VERSION.build);
        }
        const sep = url.includes('?') ? '&' : '?';
        return url + sep + 'v=' + APP_VERSION.build;
    };

    window.assetUrl = function (path) {
        return window.appendAppVersion(path);
    };
})();
