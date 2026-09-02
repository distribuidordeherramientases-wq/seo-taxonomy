(function () {
    'use strict';

    var config = window.seoImagesOptimizerSettings || null;
    if (!config) {
        return;
    }

    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (bytes < 1024) {
            return bytes + ' B';
        }
        var units = ['KB', 'MB', 'GB', 'TB'];
        var value = bytes;
        var index = -1;
        do {
            value = value / 1024;
            index++;
        } while (value >= 1024 && index < units.length - 1);
        return value.toLocaleString(undefined, {maximumFractionDigits: 2}) + ' ' + units[index];
    }

    function postBatch(offset) {
        var body = new URLSearchParams();
        body.set('action', 'seo_images_optimize_batch');
        body.set('nonce', config.nonce);
        body.set('offset', String(offset));
        body.set('limit', String(config.batchSize || 2));

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (payload) {
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Respuesta AJAX no valida.');
            }
            return payload.data;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('seo-images-optimize-local');
        var progress = document.getElementById('seo-images-optimizer-progress');
        var track = progress ? progress.querySelector('.seo-images-optimizer-progress-track span') : null;
        var status = document.getElementById('seo-images-optimizer-status');
        var stats = document.getElementById('seo-images-optimizer-stats');
        var errors = document.getElementById('seo-images-optimizer-errors');

        if (!button || !progress || !track || !status || !stats || !errors) {
            return;
        }

        button.addEventListener('click', function () {
            if (!window.confirm(config.strings.confirm)) {
                return;
            }

            var totals = {
                processed: 0,
                total: 0,
                optimizedFiles: 0,
                skippedFiles: 0,
                optimizedItems: 0,
                unchangedItems: 0,
                bytesBefore: 0,
                bytesAfter: 0,
                bytesSaved: 0,
                errors: []
            };

            button.disabled = true;
            progress.hidden = false;
            errors.hidden = true;
            errors.textContent = '';
            track.style.width = '0%';
            status.textContent = config.strings.running;
            stats.textContent = '';

            function render() {
                var percent = totals.total > 0 ? Math.min(100, Math.round((totals.processed / totals.total) * 100)) : 0;
                track.style.width = percent + '%';
                status.textContent = (totals.processed < totals.total ? config.strings.running : config.strings.done) + ' ' + totals.processed + ' / ' + totals.total + ' (' + percent + '%)';
                stats.innerHTML =
                    '<span><strong>' + totals.optimizedFiles + '</strong> archivos reducidos</span>' +
                    '<span><strong>' + totals.skippedFiles + '</strong> sin cambio</span>' +
                    '<span><strong>' + formatBytes(totals.bytesSaved) + '</strong> ahorrados</span>' +
                    '<span>' + formatBytes(totals.bytesBefore) + ' → <strong>' + formatBytes(totals.bytesAfter) + '</strong></span>';
                if (totals.errors.length) {
                    errors.hidden = false;
                    errors.textContent = totals.errors.slice(0, 10).join('\n');
                }
            }

            function run(offset) {
                postBatch(offset).then(function (data) {
                    totals.processed += Number(data.processed || 0);
                    totals.total = Number(data.total || totals.total || 0);
                    totals.optimizedFiles += Number(data.optimized_files || 0);
                    totals.skippedFiles += Number(data.skipped_files || 0);
                    totals.optimizedItems += Number(data.optimized_items || 0);
                    totals.unchangedItems += Number(data.unchanged_items || 0);
                    totals.bytesBefore += Number(data.bytes_before || 0);
                    totals.bytesAfter += Number(data.bytes_after || 0);
                    totals.bytesSaved += Number(data.bytes_saved || 0);
                    if (Array.isArray(data.errors) && data.errors.length) {
                        totals.errors = totals.errors.concat(data.errors).slice(0, 10);
                    }
                    render();

                    if (data.done) {
                        track.style.width = '100%';
                        status.textContent = config.strings.done + ' ' + totals.processed + ' / ' + totals.total + '. Ahorro total: ' + formatBytes(totals.bytesSaved) + '.';
                        button.disabled = false;
                        return;
                    }

                    run(Number(data.next_offset || (offset + Number(data.processed || 0))));
                }).catch(function (error) {
                    button.disabled = false;
                    status.textContent = config.strings.error;
                    errors.hidden = false;
                    errors.textContent = error && error.message ? error.message : config.strings.error;
                });
            }

            run(0);
        });
    });
}());
