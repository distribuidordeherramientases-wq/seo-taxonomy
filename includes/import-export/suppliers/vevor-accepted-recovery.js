(function () {
    'use strict';
    const container = document.getElementById('seo-vevor-recover');
    if (!container) return;
    const start = container.querySelector('[data-action="start"]');
    const pause = container.querySelector('[data-action="pause"]');
    const status = container.querySelector('[data-role="status"]');
    const errorsBox = container.querySelector('[data-role="errors"]');
    const ajaxUrl = container.getAttribute('data-url');
    const nonce = container.getAttribute('data-nonce');
    let running = false;
    let cursor = 0;
    let created = 0;
    let linked = 0;
    let failed = 0;

    function setRunning(value) {
        running = value;
        start.disabled = value;
        pause.disabled = !value;
    }

    function render(remaining, done) {
        status.textContent = 'Borradores nuevos: ' + created +
            ' · fichas existentes vinculadas: ' + linked +
            ' · errores: ' + failed +
            ' · ofertas sin vincular: ' + remaining +
            (done ? (remaining ? ' · quedan incidencias para revisar' : ' · conversión terminada') : '');
    }

    async function runBatch() {
        if (!running) return;
        try {
            const body = new URLSearchParams({
                action: 'seo_vevor_recover',
                nonce: nonce,
                cursor: String(cursor)
            });
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error((result.data && result.data.message) || 'Error en el servidor');
            }
            const data = result.data;
            cursor = data.cursor;
            created += data.created;
            linked += data.linked;
            failed += data.errors.length;
            if (data.errors.length) {
                errorsBox.textContent = data.errors.join(' | ');
            }
            const complete = data.done === true || data.remaining === 0;
            render(data.remaining, complete);
            if (complete) {
                setRunning(false);
                return;
            }
            if (running) {
                await runBatch();
            }
        } catch (error) {
            setRunning(false);
            status.textContent = 'Proceso detenido: ' + error.message +
                '. Los productos ya vinculados no se repetirán al reanudar.';
        }
    }

    start.addEventListener('click', function () {
        if (running) return;
        // Start a fresh scan each time. Linked offers are automatically skipped.
        cursor = 0;
        created = 0;
        linked = 0;
        failed = 0;
        errorsBox.textContent = '';
        setRunning(true);
        status.textContent = 'Convirtiendo ofertas aceptadas...';
        runBatch();
    });

    pause.addEventListener('click', function () {
        setRunning(false);
        status.textContent += ' · Pausado; puede reanudarse sin duplicar.';
    });
}());