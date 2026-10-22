(function () {
    'use strict';
    var config = window.tcsBrowserProbe;
    var root = document.querySelector('[data-tcs-browser-probe]');
    if (!root || !config || !config.target || !config.ajaxUrl) {
        return;
    }
    var button = root.querySelector('[data-tcs-probe-run]');
    var status = root.querySelector('[data-tcs-probe-status]');
    var result = root.querySelector('[data-tcs-probe-result]');
    var busy = false;

    async function check() {
        if (busy) {
            return;
        }
        busy = true;
        button.disabled = true;
        status.textContent = 'Solicitando la portada pública desde este navegador…';
        var controller = new AbortController();
        var watchdog = window.setTimeout(function () { controller.abort(); }, config.timeoutMs || 15000);
        var started = performance.now();
        var ttfb = 0;
        var code = 0;
        var redirected = false;
        var error = '';
        try {
            var response = await fetch(config.target, {
                method: 'GET',
                credentials: 'omit',
                cache: 'no-store',
                redirect: 'follow',
                signal: controller.signal
            });
            ttfb = performance.now() - started;
            code = response.status;
            redirected = response.redirected;
            await response.text();
        } catch (ex) {
            error = ex && ex.name === 'AbortError'
                ? 'Tiempo máximo de 15 segundos agotado.'
                : 'La petición del navegador no ha podido completarse (red, CORS o TLS).';
        } finally {
            window.clearTimeout(watchdog);
        }
        var ms = Math.round((performance.now() - started) * 10) / 10;
        ttfb = Math.round(ttfb * 10) / 10;
        status.textContent = (code ? 'HTTP ' + code : 'Sin respuesta') + ' · ' + ms + ' ms';
        result.textContent = error || (redirected
            ? 'Respuesta con redirección · ' + ms + ' ms. '
            : 'Respuesta recibida · ' + ms + ' ms. ') + 'La medida procede de este navegador.';
        var data = new URLSearchParams();
        data.set('action', config.action);
        data.set('nonce', config.nonce);
        data.set('status', String(code));
        data.set('duration_ms', String(ms));
        data.set('ttfb_ms', String(ttfb));
        data.set('redirected', redirected ? '1' : '0');
        data.set('error', error);
        try {
            var saved = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }
            });
            var json = await saved.json();
            if (!saved.ok || !json || !json.success) {
                status.textContent += ' · No se ha guardado en WordPress.';
            } else {
                status.textContent += ' · Muestra guardada.';
            }
        } catch (ex) {
            status.textContent += ' · Error al guardar la muestra.';
        } finally {
            busy = false;
            button.disabled = false;
        }
    }

    button.addEventListener('click', check);
    // One real remote-browser probe when this administration screen is opened.
    check();
}());
