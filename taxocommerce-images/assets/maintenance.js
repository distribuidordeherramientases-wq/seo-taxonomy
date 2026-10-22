/* global taxocommerceImagesMaintenance */
(function () {
    'use strict';
    var config = window.taxocommerceImagesMaintenance;
    if (!config || !window.fetch) { return; }
    document.querySelectorAll('[data-tci-maintenance]').forEach(function (section) {
        var busy = false;
        var paused = false;
        var output = section.querySelector('[data-tci-progress]');
        var buttons = section.querySelectorAll('[data-tci-operation]');
        var pause = section.querySelector('[data-tci-pause]');
        if (pause) { pause.addEventListener('click', function () { paused = true; }); }
        buttons.forEach(function (button) {
            button.addEventListener('click', async function () {
                if (busy) { return; }
                var operation = button.dataset.tciOperation;
                if (operation !== 'start' && operation !== 'scan') { return; }
                busy = true;
                paused = false;
                buttons.forEach(function (item) { item.disabled = true; });
                try {
                    while (!paused) {
                        var form = new URLSearchParams();
                        form.set('action', 'taxocommerce_images_maintenance');
                        form.set('nonce', config.nonce);
                        form.set('mode', section.dataset.tciMaintenance);
                        form.set('operation', operation);
                        var response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body: form });
                        var result = await response.json();
                        if (!response.ok || !result.success) {
                            throw new Error(result.data && result.data.message ? result.data.message : 'No se pudo ejecutar el lote.');
                        }
                        var state = result.data.state || {};
                        if (result.data.paused) {
                            operation = 'scan';
                            var seconds = Math.max(1, Number(result.data.retry_after || 10));
                            var cpuMessage = result.data.cpu && result.data.cpu.message ? result.data.cpu.message : 'CPU no disponible para continuar.';
                            output.textContent = cpuMessage + ' Progreso guardado: ' + (state.scanned || 0) + ' / ' + (state.total || 0) + '. Reintentando en ' + seconds + ' s.';
                            await new Promise(function (resolve) { window.setTimeout(resolve, seconds * 1000); });
                            continue;
                        }
                        output.textContent = 'Analizadas: ' + (state.scanned || 0) + ' / ' + (state.total || 0) + '. El progreso está guardado.';
                        if (result.data.done) { window.location.reload(); return; }
                        operation = 'scan';
                        await new Promise(function (resolve) { window.setTimeout(resolve, 200); });
                    }
                    output.textContent += ' Pausado al finalizar el lote. Puedes continuar sin repetir lo analizado.';
                } catch (error) {
                    output.textContent = 'Proceso detenido: ' + error.message + ' El último lote completado está guardado.';
                } finally {
                    busy = false;
                    buttons.forEach(function (item) { item.disabled = false; });
                }
            });
        });
    });
}());
