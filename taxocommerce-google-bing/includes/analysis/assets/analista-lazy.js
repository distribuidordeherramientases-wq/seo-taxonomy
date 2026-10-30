/* TaxoCommerce SEO: Analista report panels (loaded via wp_enqueue_script). */
jQuery(function($) {
            var running = false;
            var ajaxUrl = window.seoAnalistaLazyConfig.ajaxUrl;
            var nonce = window.seoAnalistaLazyConfig.nonce;
            var days = window.seoAnalistaLazyConfig.days;

            $('.seo-analista-lazy-button').on('click', function() {
                if (running) {
                    return;
                }

                var button = $(this);
                var report = button.data('report');
                var label = button.find('strong').first().text();

                running = true;
                $('.seo-analista-lazy-button').prop('disabled', true).removeClass('is-running');
                button.addClass('is-running');
                $('#seo-analista-lazy-result').empty();
                $('#seo-analista-lazy-status').html('<span class="spinner is-active"></span><strong>Generando ' + $('<div>').text(label).html() + '…</strong> Solo se está ejecutando este bloque.');

                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    timeout: 240000,
                    data: {
                        action: 'taxocommerce_insights_analysis',
                        nonce: nonce,
                        report: report,
                        days: days
                    }
                }).done(function(response) {
                    if (response && response.success) {
                        var seconds = response.data && response.data.seconds ? response.data.seconds : 0;
                        var version = response.data && response.data.version ? response.data.version : '';
                        $('#seo-analista-lazy-status').html('<strong>' + $('<div>').text(label).html() + ' completado.</strong> ' + seconds + ' s' + (version ? ' · Analista ' + $('<div>').text(version).html() : ''));
                        $('#seo-analista-lazy-result').html(response.data.html || '');
                    } else {
                        var message = response && response.data && response.data.message ? response.data.message : 'No se pudo generar el informe.';
                        $('#seo-analista-lazy-status').html('<div class="notice notice-error inline"><p>' + $('<div>').text(message).html() + '</p></div>');
                    }
                }).fail(function(xhr, textStatus) {
                    var message = 'Error AJAX al generar el informe.';
                    if (textStatus === 'timeout') {
                        message = 'El informe superó 240 segundos. Este bloque necesita dividirse todavía más.';
                    } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    $('#seo-analista-lazy-status').html('<div class="notice notice-error inline"><p>' + $('<div>').text(message).html() + '</p></div>');
                }).always(function() {
                    running = false;
                    $('.seo-analista-lazy-button').prop('disabled', false).removeClass('is-running');
                });
            });
        });
