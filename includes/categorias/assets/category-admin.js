(function ($) {
    'use strict';

    var config = window.seoCategoryAdminData || {};
    var seoDatosRelaciones = Array.isArray(config.relations) ? config.relations : [];
    var seoRelacionesCategorias = Array.isArray(config.categories) ? config.categories : [];
    var seoUrlsPrecalculadas = config.urls && typeof config.urls === 'object' ? config.urls : {};
    var seoUrlsDestinoPorFila = {};

    function option(value, label) {
        return $('<option>', { value: String(value || ''), text: String(label || '') });
    }

    window.seoFiltrarCascada = function (termId, nivelModificado) {
        var clusterSel = $('#cluster_' + termId);
        var hubPSel = $('#hub_p_' + termId);
        var hubSSel = $('#hub_s_' + termId);
        var catDestSel = $('#cat_dest_' + termId);
        var previewDiv = $('#url_preview_' + termId);
        var btnRelOnly = $('#btn_rel_only_' + termId);

        if (nivelModificado === 1) {
            var clusterId = clusterSel.val();
            hubPSel.empty().append(option('', '-- Seleccionar Hub Primario --')).attr('disabled', 'disabled');
            hubSSel.empty().append(option('', '-- Esperando Hub P. --')).attr('disabled', 'disabled');
            catDestSel.empty().append(option('', '-- Esperando Hub S. --')).attr('disabled', 'disabled');

            if (clusterId !== '') {
                var primarios = seoDatosRelaciones.filter(function (row) {
                    return String(row.source_id) === String(clusterId) && row.target_type === 'hub_primary';
                });
                if (primarios.length > 0) {
                    primarios.forEach(function (item) {
                        hubPSel.append(option(item.target_id, item.target_title));
                    });
                    hubPSel.removeAttr('disabled');
                } else {
                    hubPSel.empty().append(option('', 'Sin Hubs Primarios'));
                }
            }
        }

        if (nivelModificado === 2) {
            var hubPId = hubPSel.val();
            hubSSel.empty().append(option('', '-- Seleccionar Hub Secundario --')).attr('disabled', 'disabled');
            catDestSel.empty().append(option('', '-- Esperando Hub S. --')).attr('disabled', 'disabled');

            if (hubPId !== '') {
                var secundarios = seoDatosRelaciones.filter(function (row) {
                    return String(row.source_id) === String(hubPId)
                        && (row.target_type === 'hub_secondary' || row.target_type === 'hub_secundario');
                });
                if (secundarios.length > 0) {
                    secundarios.forEach(function (item) {
                        hubSSel.append(option(item.target_id, item.target_title));
                    });
                    hubSSel.removeAttr('disabled');
                } else {
                    hubSSel.empty().append(option('', 'Sin Hubs Secundarios'));
                }
            }
        }

        if (nivelModificado === 3) {
            var hubSId = hubSSel.val();
            catDestSel.empty().append(option('', '-- Seleccionar Categoría Destino --')).attr('disabled', 'disabled');

            if (hubSId !== '') {
                var categoriasFiltradas = seoRelacionesCategorias.filter(function (row) {
                    return String(row.hub_secundario_id) === String(hubSId);
                });
                if (categoriasFiltradas.length > 0) {
                    categoriasFiltradas.forEach(function (item) {
                        if (parseInt(item.cat_term_id, 10) !== parseInt(termId, 10)) {
                            catDestSel.append(option(item.cat_term_id, item.cat_nombre));
                        }
                    });
                    catDestSel.removeAttr('disabled');
                } else {
                    catDestSel.empty().append(option('', 'Sin Categorías vinculadas'));
                }
            }
        }

        var finalUrl = '';
        if (catDestSel.val()) {
            finalUrl = seoUrlsPrecalculadas['cat_' + catDestSel.val()] || '';
        } else if (hubSSel.val()) {
            finalUrl = seoUrlsPrecalculadas['post_' + hubSSel.val()] || '';
        } else if (hubPSel.val()) {
            finalUrl = seoUrlsPrecalculadas['post_' + hubPSel.val()] || '';
        } else if (clusterSel.val()) {
            finalUrl = seoUrlsPrecalculadas['post_' + clusterSel.val()] || '';
        }

        seoUrlsDestinoPorFila[termId] = finalUrl;

        if (finalUrl !== '') {
            previewDiv.empty().append($('<strong>', { text: finalUrl }).css('color', '#006505'));
            btnRelOnly.removeAttr('disabled').css({
                background: '#e2f0fd',
                'border-color': '#2271b1',
                color: '#2271b1',
                cursor: 'pointer'
            });
        } else {
            previewDiv.empty().append($('<i>', { text: 'Ningún destino seleccionado' }));
            btnRelOnly.attr('disabled', 'disabled').css({
                background: '#f6f7f7',
                'border-color': '#dcdcde',
                color: '#a7aaad',
                cursor: 'not-allowed'
            });
        }
    };

    window.seoProcesarAccionV2 = function (termId) {
        var btnBorrar = $('#btn_redirect_' + termId);
        var fila = $('#cat_row_' + termId);

        btnBorrar.attr('disabled', 'disabled').css('background', '#f0f0f1');

        $.ajax({
            url: String(config.ajaxUrl || window.ajaxurl || ''),
            type: 'POST',
            data: {
                action: 'seo_borrar_y_redirigir_categoria',
                term_id: parseInt(termId, 10) || 0,
                nonce: String(config.deleteNonce || '')
            },
            success: function (response) {
                if (!response || !response.success) {
                    window.alert('RESPUESTA RECIBIDA: ' + JSON.stringify(response));
                    btnBorrar.removeAttr('disabled').css({
                        background: '#bae0ba',
                        'border-color': '#00a32a',
                        color: '#006505',
                        cursor: 'pointer'
                    });
                    return;
                }

                fila.css({
                    background: '#ffdede',
                    'border-color': '#cc0000',
                    opacity: '0.3',
                    transform: 'scale(0.96)'
                });

                window.setTimeout(function () {
                    fila.slideUp(300, function () {
                        $(this).remove();
                    });
                }, 300);
            },
            error: function (xhr, textStatus, errorThrown) {
                window.alert(
                    'Error AJAX\n'
                    + 'Estado: ' + String(textStatus || '') + '\n'
                    + 'HTTP: ' + String(xhr && xhr.status ? xhr.status : '') + '\n'
                    + 'Error: ' + String(errorThrown || '') + '\n'
                    + 'Respuesta: ' + String(xhr && xhr.responseText ? xhr.responseText : '')
                );

                btnBorrar.removeAttr('disabled').css({
                    background: '#bae0ba',
                    'border-color': '#00a32a',
                    color: '#006505',
                    cursor: 'pointer'
                });
            }
        });
    };
})(jQuery);
