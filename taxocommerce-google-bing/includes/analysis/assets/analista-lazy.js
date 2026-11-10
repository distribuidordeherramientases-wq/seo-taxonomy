/* TaxoCommerce Insights: sequential Analysis reports, including mobile browsers. */
jQuery(function ($) {
    'use strict';

    var config = window.seoAnalistaLazyConfig || {};
    var buttons = $('.seo-analista-lazy-button');
    var fullButton = $('#tgb-analysis-run-all');
    var stopButton = $('#tgb-analysis-cancel');
    var status = $('#seo-analista-lazy-status');
    var result = $('#seo-analista-lazy-result');
    var running = false;
    var cancelled = false;
    var pending = null;
    var planDownloadUrl = null;

    function releasePlanJson() {
        if (planDownloadUrl !== null && window.URL && window.URL.revokeObjectURL) {
            window.URL.revokeObjectURL(planDownloadUrl);
        }
        planDownloadUrl = null;
    }

    function showPlanJson(container, data) {
        if (!data || !data.plan_export || !Array.isArray(data.plan_export.plan_accion)) {
            return;
        }
        try {
            var content = JSON.stringify(data.plan_export, null, 2);
            if (typeof Blob === 'undefined' || !window.URL || !window.URL.createObjectURL) {
                container.append($('<p>').addClass('description').text('Este navegador no permite descargar el JSON del plan de acción.'));
                return;
            }
            planDownloadUrl = window.URL.createObjectURL(new Blob([content], {
                type: 'application/json;charset=utf-8'
            }));
            var stamp = String(data.plan_export.generated_at || '').replace(/[^0-9]/g, '').slice(0, 14);
            var filename = 'taxocommerce-plan-de-accion-' + (stamp || 'actual') + '.json';
            var download = $('<a>').addClass('button button-secondary')
                .attr({ href: planDownloadUrl, download: filename })
                .text(config.planDownloadLabel || 'Descargar JSON del plan de acción');
            var open = $('<a>').addClass('button')
                .attr({ href: planDownloadUrl, target: '_blank', rel: 'noopener noreferrer' })
                .text(config.planOpenLabel || 'Abrir JSON del plan (móvil)');
            container.append($('<div>').addClass('tgb-plan-json-actions').append(download, ' ', open));
        } catch (error) {
            container.append($('<p>').addClass('description').text('No se pudo preparar el JSON del plan de acción.'));
        }
    }

    function setBusy(busy) {
        running = busy;
        buttons.prop('disabled', busy);
        fullButton.prop('disabled', busy);
        stopButton.prop('disabled', !busy);
    }

    function showStatus(message, failed) {
        status.empty().append(
            $('<div>').toggleClass('notice notice-error inline', !!failed).append(
                $('<p>').text(message)
            )
        );
    }

    function items() {
        return buttons.map(function () {
            var button = $(this);
            return {
                report: String(button.attr('data-report') || ''),
                label: button.find('strong').first().text()
            };
        }).get().filter(function (item) { return item.report !== ''; });
    }

    function run(queue, complete) {
        if (running || !queue.length) {
            return;
        }
        if (!config.ajaxUrl || !config.nonce) {
            showStatus('No se ha podido inicializar Analista. Recarga la página e inténtalo de nuevo.', true);
            return;
        }

        cancelled = false;
        releasePlanJson();
        setBusy(true);
        result.empty();
        var finished = 0;
        var total = queue.length;

        function finish(message, error) {
            pending = null;
            setBusy(false);
            showStatus(message, error);
        }

        function next() {
            if (cancelled) {
                finish('Proceso detenido. Se conservan los ' + finished + ' bloques completados; el informe NO está completo.', true);
                return;
            }
            if (finished === total) {
                finish((complete ? 'Informe completo generado: ' : 'Informe generado: ') + finished + ' de ' + total + ' bloques.', false);
                return;
            }

            var item = queue[finished];
            showStatus('Generando ' + (finished + 1) + ' de ' + total + ': ' + item.label + '…', false);
            pending = $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                timeout: 240000,
                data: {
                    action: 'taxocommerce_insights_analysis',
                    nonce: config.nonce,
                    report: item.report,
                    days: config.days
                }
            }).done(function (response) {
                if (cancelled) {
                    return;
                }
                if (!response || !response.success || !response.data || typeof response.data.html !== 'string') {
                    var explanation = response && response.data && response.data.message ?
                        response.data.message : 'Respuesta del servidor no válida.';
                    finish('Error en ' + item.label + ': ' + explanation + ' (' + finished + '/' + total + ' completados).', true);
                    return;
                }
                var reportContainer = $('<div>').addClass('tgb-analysis-report')
                    .attr('data-report', item.report).html(response.data.html);
                result.append(reportContainer);
                if (item.report === 'directrices') {
                    showPlanJson(reportContainer, response.data);
                }
                finished++;
                next();
            }).fail(function (xhr, reason) {
                if (cancelled || reason === 'abort') {
                    finish('Proceso detenido. Se conservan ' + finished + ' de ' + total + ' bloques; el informe NO está completo.', true);
                    return;
                }
                var message = reason === 'timeout' ? 'Tiempo de espera agotado.' :
                    (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
                    'Error de conexión o servidor.';
                finish('Error en ' + item.label + ': ' + message + ' (' + finished + '/' + total + ' completados).', true);
            });
        }
        next();
    }

    buttons.on('click', function () {
        var button = $(this);
        run([{
            report: String(button.attr('data-report') || ''),
            label: button.find('strong').first().text()
        }], false);
    });

    fullButton.on('click', function () {
        run(items(), true);
    });

    stopButton.on('click', function () {
        if (!running) {
            return;
        }
        cancelled = true;
        if (pending && typeof pending.abort === 'function') {
            pending.abort();
        }
    });
});
