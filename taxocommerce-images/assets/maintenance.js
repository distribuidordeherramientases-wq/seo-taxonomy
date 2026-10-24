/* global taxocommerceImagesMaintenance */
(function () {
    'use strict';
    var config = window.taxocommerceImagesMaintenance;
    if (!config || !window.fetch) { return; }
    document.querySelectorAll('[data-tci-priority]').forEach(function (slider) {
        var form = slider.closest('form');
        var output = form ? form.querySelector('[data-tci-priority-output]') : null;
        var labels = { 1: 'Suave', 2: 'Baja', 3: 'Normal', 4: 'Alta', 5: 'Máxima' };
        if (!output) { return; }
        slider.addEventListener('input', function () {
            output.innerHTML = '<strong>' + slider.value + ' · ' + labels[slider.value] + '</strong>';
        });
    });

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
                        var load = result.data.load || {};
                        var cpu = load.cpu_percent === null || typeof load.cpu_percent === 'undefined' ? 'CPU no disponible' : load.cpu_percent + ' % CPU petición';
                        var next = load.next_batch || load.batch || 0;
                        output.textContent = 'Analizadas: ' + (state.scanned || 0) + ' / ' + (state.total || 0) + '. Último lote: ' + (load.items || 0) + ' elementos · ' + cpu + ' · ' + (load.elapsed || 0) + ' s · siguiente lote ' + next + '.';
                        if (result.data.done) { window.location.reload(); return; }
                        operation = 'scan';
                        await new Promise(function (resolve) { window.setTimeout(resolve, result.data.delay || config.delay || 4000); });
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
