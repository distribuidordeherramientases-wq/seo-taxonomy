/* global taxocommerceImagesJobs */
/**
 * Start persistent server-side work. The browser no longer dispatches batches.
 */
(function () {
    'use strict';
    var optimizer = document.getElementById('tci-images-optimizer-run');
    var webp = document.getElementById('tci-images-webp-run');
    var message = document.getElementById('tci-images-jobs-status');
    if (!optimizer || !webp || !message) { return; }

    function start(mode) {
        var text = mode === 'webp'
            ? 'La conversión WebP modifica archivos y referencias. Comprueba tu copia de seguridad. ¿Iniciar en segundo plano?'
            : 'La optimización modifica ficheros Media. Comprueba tu copia de seguridad. ¿Iniciar en segundo plano?';
        if (!window.confirm(text)) { return; }
        var request = window.taxocommerceImagesBackgroundRequest;
        if (typeof request !== 'function') {
            message.textContent = 'El controlador de trabajos en segundo plano no está disponible.';
            return;
        }
        optimizer.disabled = true;
        webp.disabled = true;
        request(mode, 'start').then(function (job) {
            message.textContent = 'Trabajo ' + (job.status || 'iniciado')
                + ' en servidor. Puedes cerrar esta pestaña; consulta el estado cuando vuelvas.';
        }).catch(function (error) {
            message.textContent = 'No se pudo iniciar: ' + error.message;
        }).then(function () {
            optimizer.disabled = false;
            webp.disabled = false;
        });
    }
    optimizer.addEventListener('click', function () { start('optimize'); });
    webp.addEventListener('click', function () { start('webp'); });
}());
