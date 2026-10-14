jQuery(function ($) {
    'use strict';

    var config = window.seoReportsLazy || {};
    var ajaxUrl = String(config.ajaxUrl || '');
    var nonce = String(config.nonce || '');
    var running = false;

    function safeText(value) {
        return $('<div>').text(value || '').html();
    }

    $('.seo-reports-lazy-card').each(function () {
        var card = $(this);
        var createButton = card.find('.seo-reports-create');
        var closeButton = card.find('.seo-reports-close');
        var status = card.find('.seo-reports-lazy-status');
        var result = card.find('.seo-reports-lazy-result');

        createButton.on('click', function () {
            if (running) {
                return;
            }

            var report = card.data('seo-report');
            var label = card.find('h3').first().text();
            running = true;

            $('.seo-reports-create').prop('disabled', true);
            card.addClass('is-running');
            result.hide().empty();
            closeButton.hide();
            status.addClass('is-visible').html('<span class="spinner is-active"></span><strong>Generando ' + safeText(label) + '…</strong>');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                timeout: 240000,
                data: {
                    action: 'seo_reports_general_partial',
                    nonce: nonce,
                    report: report
                }
            }).done(function (response) {
                if (response && response.success) {
                    var seconds = response.data && response.data.seconds ? response.data.seconds : 0;
                    status.html('<strong>' + safeText(label) + ' generado.</strong> ' + seconds + ' s');
                    result.html(response.data.html || '').show();
                    closeButton.show();
                    createButton.text('Actualizar informe');
                } else {
                    var message = response && response.data && response.data.message ? response.data.message : 'No se pudo generar el informe.';
                    status.html('<div class="notice notice-error inline"><p>' + safeText(message) + '</p></div>');
                }
            }).fail(function (xhr, textStatus) {
                var message = 'Error AJAX al generar el informe.';
                if (textStatus === 'timeout') {
                    message = 'El informe superó 240 segundos. Conviene dividir este bloque o revisar la consulta que lo genera.';
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                status.html('<div class="notice notice-error inline"><p>' + safeText(message) + '</p></div>');
            }).always(function () {
                running = false;
                $('.seo-reports-create').prop('disabled', false);
                card.removeClass('is-running');
            });
        });

        closeButton.on('click', function () {
            result.empty().hide();
            status.removeClass('is-visible').empty();
            closeButton.hide();
            createButton.text('Crear informe');
        });
    });
});
