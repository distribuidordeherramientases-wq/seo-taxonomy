/* global URLSearchParams */
/**
 * Browser-assisted Media audit. One authenticated request and one bounded
 * adaptive batch at a time; no fixed delay between successful batches.
 */
(function () {
    'use strict';

    var config = window.taxocommerceImagesAudit || {};
    var root = document.querySelector('[data-tci-audit-runner]');
    if (!root || !config.ajax || !config.nonce || typeof window.fetch !== 'function') {
        return;
    }

    var progress = document.getElementById('tc-images-audit-progress');
    var bar = document.getElementById('tci-audit-progress-bar');
    var pause = document.getElementById('tci-audit-pause');
    var resume = document.getElementById('tci-audit-resume');
    var paused = false;
    var pending = false;
    var finished = false;
    var failures = 0;
    var scheduled = null;

    function schedule(delay) {
        if (paused || finished || scheduled !== null) { return; }
        scheduled = window.setTimeout(function () {
            scheduled = null;
            step();
        }, delay);
    }

    function controls() {
        if (pause) { pause.disabled = paused || finished; }
        if (resume) { resume.disabled = !paused || finished; }
    }

    function display(data) {
        var total = Number(data.total || 0);
        var scanned = Number(data.scanned || 0);
        var load = data.load || {};
        var items = Number(load.items || 0);
        var elapsed = Number(load.elapsed || 0);
        var next = Number(load.next_batch || 0);
        var requested = Number(load.batch || 0);
        var speed = Number(data.priority || 3);
        var rate = Number(load.items_per_second || 0);
        var cpu = typeof load.cpu_percent === 'number'
            ? load.cpu_percent.toFixed(1) + '% CPU PHP' : 'CPU PHP no disponible';
        var memory = typeof load.memory_percent === 'number'
            ? load.memory_percent.toFixed(1) + '% memoria PHP' : 'memoria PHP no disponible';

        if (bar) {
            bar.max = Math.max(1, total);
            bar.value = Math.min(total, scanned);
        }
        if (progress) {
            progress.textContent = scanned + ' / ' + total + ' imágenes · velocidad ' + speed
                + '/5 · último lote ' + items + '/' + requested + ' en '
                + elapsed.toFixed(2) + ' s · siguiente objetivo ' + next + ' imágenes · '
                + rate.toFixed(1) + ' imágenes/s · ' + cpu + ' · ' + memory
                + (load.reason ? ' · ' + load.reason : '');
        }
        if (data.status === 'completed') {
            finished = true;
            controls();
            if (progress) { progress.textContent += ' · Auditoría completada'; }
            window.location.reload();
        }
    }

    function step() {
        if (paused || pending || finished) { return; }
        pending = true;

        var fields = new URLSearchParams();
        fields.set('action', 'taxocommerce_images_audit_step');
        fields.set('nonce', config.nonce);
        window.fetch(config.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            body: fields
        }).then(function (response) {
            if (response.status === 409) {
                var busy = new Error('Otro lote está en ejecución');
                busy.retryable = true;
                throw busy;
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (reply) {
            if (!reply || !reply.success || !reply.data) {
                throw new Error('No se pudo confirmar el lote de auditoría');
            }
            failures = 0;
            display(reply.data);
            if (!paused && !finished) {
                // No fixed waiting between successful batches. The adaptive
                // regulator controls rows and request duration on the server.
                schedule(0);
            }
        }).catch(function (error) {
            failures += 1;
            if (failures <= 5 && !paused) {
                if (progress) {
                    progress.textContent = error.message
                        + '. Recuperación automática ' + failures + '/5; se conserva el cursor.';
                }
                schedule(error.retryable ? 600 : Math.min(5000, failures * 1000));
            } else {
                paused = true;
                if (progress) {
                    progress.textContent = 'Auditoría interrumpida. Se conserva el cursor. '
                        + 'Pulsa Reanudar o Continuar siguiente lote (manual).';
                }
                controls();
            }
        }).then(function () {
            pending = false;
        });
    }

    if (pause) {
        pause.addEventListener('click', function () {
            paused = true;
            if (scheduled !== null) {
                window.clearTimeout(scheduled);
                scheduled = null;
            }
            controls();
            if (progress) {
                progress.textContent += ' · Pausa solicitada; el lote activo puede finalizar.';
            }
        });
    }
    if (resume) {
        resume.addEventListener('click', function () {
            paused = false;
            failures = 0;
            controls();
            if (progress) { progress.textContent += ' · Reanudando…'; }
            schedule(0);
        });
    }

    controls();
    schedule(0);
}());
