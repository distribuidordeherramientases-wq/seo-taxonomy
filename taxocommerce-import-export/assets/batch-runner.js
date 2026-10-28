/* global URLSearchParams */
/**
 * Keep the active CSV queue moving while the importer is open.
 * The server independently launches signed loopbacks when the tab is closed.
 */
(function () {
    'use strict';
    const config = window.tcIeBatchRunner;
    if (!config || !config.ajaxUrl || !config.nonce) { return; }
    const output = document.getElementById('tc-ie-batch-live');
    let running = false;
    let last = '';
    let failed = 0;
    let stopped = false;

    function show(data) {
        if (!output) { return; }
        if (!data) { return; }
        const text = [
            'Estado: ' + (data.status || 'sin datos'),
            'Archivo: ' + (data.file || '—'),
            'Filas: ' + Number(data.rows || 0),
            'Último lote: ' + Number(data.last_batch || 0) + ' filas de '
                + Number(data.target_batch || 0) + ' objetivo en '
                + Number(data.elapsed || 0).toFixed(2) + ' s',
            'Siguiente lote: ' + Number(data.next_batch || 0) + ' filas',
            'Rendimiento: ' + Number(data.rows_per_second || 0).toFixed(2) + ' filas/s',
            'Tiempo medio/fila: ' + Number(data.average_row_seconds || 0).toFixed(2) + ' s',
            'Guardados WooCommerce: ' + Number(data.row_save_seconds || 0).toFixed(2) + ' s/lote',
            'SQL: ' + Number(data.db_queries_per_row || 0).toFixed(1) + ' consultas/fila',
            'Memoria PHP: ' + Number(data.memory_percent || 0).toFixed(1) + '%',
            'CPU PHP (no servidor): ' + Number(data.cpu_percent || 0).toFixed(1) + '%',
            'Regulación: ' + (data.reason || 'sin muestra'),
            data.message || ''
        ].join(' · ');
        if (last !== text) {
            output.textContent = text;
            last = text;
        }
    }

    function step() {
        if (stopped || running) { return; }
        running = true;
        const body = new URLSearchParams({
            action: 'taxocommerce_ie_batch_step',
            nonce: config.nonce
        });
        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (res) {
                if (!res || !res.success) { throw new Error('Respuesta incorrecta de WordPress'); }
                failed = 0;
                show(res.data);
                if (res.data && res.data.enabled) {
                    // As soon as one bounded request completes, issue the next.
                    // No artificial 1-15 second delays between successful lots.
                    window.setTimeout(step, res.data.busy ? 500 : 0);
                } else {
                    stopped = true;
                }
            })
            .catch(function (error) {
                failed++;
                if (output) {
                    output.textContent = 'No se pudo continuar desde esta pestaña: '
                        + error.message + ' · Reintento ' + failed + '/5. '
                        + 'La recuperación del servidor sigue disponible.';
                }
                if (failed <= 5) {
                    window.setTimeout(step, Math.min(5000, failed * 1000));
                } else {
                    stopped = true;
                }
            })
            .finally(function () { running = false; });
    }
    // Allow microtask completion before first request.
    window.setTimeout(step, 0);
}());
