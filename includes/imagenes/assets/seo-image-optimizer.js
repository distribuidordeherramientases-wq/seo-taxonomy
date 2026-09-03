(function () {
    'use strict';

    var optimizeConfig = window.seoImagesOptimizerSettings || null;
    var webpConfig = window.seoImagesWebpSettings || null;
    if (!optimizeConfig && !webpConfig) {
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

    function ajaxPost(config, fields) {
        var body = new URLSearchParams();
        Object.keys(fields).forEach(function (key) {
            body.set(key, String(fields[key]));
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                return response.json().catch(function () {
                    return null;
                }).then(function (payload) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : 'HTTP ' + response.status;
                    throw new Error(message);
                });
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
        var optimizeButton = document.getElementById('seo-images-optimize-local');
        var webpButton = document.getElementById('seo-images-convert-webp');
        var progress = document.getElementById('seo-images-optimizer-progress');
        var track = progress ? progress.querySelector('.seo-images-optimizer-progress-track span') : null;
        var status = document.getElementById('seo-images-optimizer-status');
        var stats = document.getElementById('seo-images-optimizer-stats');
        var errors = document.getElementById('seo-images-optimizer-errors');
        var busy = false;

        if (!progress || !track || !status || !stats || !errors) {
            return;
        }

        function setBusy(value) {
            busy = value;
            if (optimizeButton) {
                optimizeButton.disabled = value;
            }
            if (webpButton) {
                webpButton.disabled = value || (webpConfig && Number(webpConfig.supported) !== 1);
            }
        }

        function resetProgress(message) {
            progress.hidden = false;
            errors.hidden = true;
            errors.textContent = '';
            track.style.width = '0%';
            status.textContent = message || '';
            stats.textContent = '';
        }

        function showErrors(list) {
            if (Array.isArray(list) && list.length) {
                errors.hidden = false;
                errors.textContent = list.slice(0, 10).join('\n');
            }
        }

        if (webpButton && webpConfig && Number(webpConfig.supported) !== 1) {
            webpButton.disabled = true;
            webpButton.title = webpConfig.strings.unsupported;
        }

        if (optimizeButton && optimizeConfig) {
            optimizeButton.addEventListener('click', function () {
                if (busy || !window.confirm(optimizeConfig.strings.confirm)) {
                    return;
                }

                var totals = {
                    processed: 0,
                    total: 0,
                    optimizedFiles: 0,
                    skippedFiles: 0,
                    bytesBefore: 0,
                    bytesAfter: 0,
                    bytesSaved: 0,
                    errors: []
                };

                setBusy(true);
                resetProgress(optimizeConfig.strings.running);

                function render() {
                    var percent = totals.total > 0 ? Math.min(100, Math.round((totals.processed / totals.total) * 100)) : 0;
                    track.style.width = percent + '%';
                    status.textContent = optimizeConfig.strings.running + ' ' + totals.processed + ' / ' + totals.total + ' (' + percent + '%)';
                    stats.innerHTML =
                        '<span><strong>' + totals.optimizedFiles + '</strong> archivos reducidos</span>' +
                        '<span><strong>' + totals.skippedFiles + '</strong> sin cambio</span>' +
                        '<span><strong>' + formatBytes(totals.bytesSaved) + '</strong> ahorrados</span>' +
                        '<span>' + formatBytes(totals.bytesBefore) + ' → <strong>' + formatBytes(totals.bytesAfter) + '</strong></span>';
                    showErrors(totals.errors);
                }

                function run(offset) {
                    ajaxPost(optimizeConfig, {
                        action: 'seo_images_optimize_batch',
                        nonce: optimizeConfig.nonce,
                        offset: offset,
                        limit: optimizeConfig.batchSize || 2
                    }).then(function (data) {
                        totals.processed += Number(data.processed || 0);
                        totals.total = Number(data.total || totals.total || 0);
                        totals.optimizedFiles += Number(data.optimized_files || 0);
                        totals.skippedFiles += Number(data.skipped_files || 0);
                        totals.bytesBefore += Number(data.bytes_before || 0);
                        totals.bytesAfter += Number(data.bytes_after || 0);
                        totals.bytesSaved += Number(data.bytes_saved || 0);
                        if (Array.isArray(data.errors) && data.errors.length) {
                            totals.errors = totals.errors.concat(data.errors).slice(0, 10);
                        }
                        render();

                        if (data.done) {
                            track.style.width = '100%';
                            status.textContent = optimizeConfig.strings.done + ' ' + totals.processed + ' / ' + totals.total + '. Ahorro total: ' + formatBytes(totals.bytesSaved) + '.';
                            setBusy(false);
                            return;
                        }

                        run(Number(data.next_offset || (offset + Number(data.processed || 0))));
                    }).catch(function (error) {
                        setBusy(false);
                        status.textContent = optimizeConfig.strings.error;
                        errors.hidden = false;
                        errors.textContent = error && error.message ? error.message : optimizeConfig.strings.error;
                    });
                }

                run(0);
            });
        }

        if (webpButton && webpConfig) {
            webpButton.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                if (Number(webpConfig.supported) !== 1) {
                    window.alert(webpConfig.strings.unsupported);
                    return;
                }
                if (!window.confirm(webpConfig.strings.confirm)) {
                    return;
                }

                var totals = {
                    processed: 0,
                    total: null,
                    converted: 0,
                    skipped: 0,
                    filesDeleted: 0,
                    refsUpdated: 0,
                    bytesBefore: 0,
                    bytesAfter: 0,
                    bytesSaved: 0,
                    errors: []
                };

                setBusy(true);
                resetProgress(webpConfig.strings.running);

                function render(done) {
                    var denominator = totals.total === null ? 0 : totals.total;
                    var percent = denominator > 0 ? Math.min(100, Math.round((totals.processed / denominator) * 100)) : (done ? 100 : 0);
                    track.style.width = percent + '%';
                    status.textContent = (done ? webpConfig.strings.done : webpConfig.strings.running) + ' ' + totals.processed + ' / ' + denominator + ' (' + percent + '%)';
                    stats.innerHTML =
                        '<span><strong>' + totals.converted + '</strong> attachments convertidos</span>' +
                        '<span><strong>' + totals.skipped + '</strong> omitidos</span>' +
                        '<span><strong>' + totals.filesDeleted + '</strong> JPG/PNG eliminados</span>' +
                        '<span><strong>' + totals.refsUpdated + '</strong> referencias actualizadas</span>' +
                        '<span><strong>' + formatBytes(totals.bytesSaved) + '</strong> liberados</span>' +
                        '<span>' + formatBytes(totals.bytesBefore) + ' → <strong>' + formatBytes(totals.bytesAfter) + '</strong></span>';
                    showErrors(totals.errors);
                }

                function run(afterId) {
                    ajaxPost(webpConfig, {
                        action: 'seo_images_webp_batch',
                        nonce: webpConfig.nonce,
                        after_id: afterId,
                        limit: webpConfig.batchSize || 1
                    }).then(function (data) {
                        if (totals.total === null) {
                            totals.total = Number(data.total || 0);
                        }
                        totals.processed += Number(data.processed || 0);
                        totals.converted += Number(data.converted || 0);
                        totals.skipped += Number(data.skipped || 0);
                        totals.filesDeleted += Number(data.files_deleted || 0);
                        totals.refsUpdated += Number(data.refs_updated || 0);
                        totals.bytesBefore += Number(data.bytes_before || 0);
                        totals.bytesAfter += Number(data.bytes_after || 0);
                        totals.bytesSaved += Number(data.bytes_saved || 0);
                        if (Array.isArray(data.errors) && data.errors.length) {
                            totals.errors = totals.errors.concat(data.errors).slice(0, 10);
                        }
                        render(Boolean(data.done));

                        if (data.done) {
                            track.style.width = '100%';
                            status.textContent = webpConfig.strings.done + ' ' + totals.converted + ' convertidos, ' + totals.skipped + ' omitidos. Espacio liberado: ' + formatBytes(totals.bytesSaved) + '.';
                            setBusy(false);
                            return;
                        }

                        run(Number(data.next_after_id || afterId));
                    }).catch(function (error) {
                        setBusy(false);
                        status.textContent = webpConfig.strings.error;
                        errors.hidden = false;
                        errors.textContent = error && error.message ? error.message : webpConfig.strings.error;
                    });
                }

                run(0);
            });
        }
    });
}());
