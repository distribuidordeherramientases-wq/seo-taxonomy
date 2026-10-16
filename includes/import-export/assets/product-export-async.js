/* Generación progresiva del inventario WooCommerce V2, completo o reducido. */
(function () {
    'use strict';

    function init() {
        var config = window.seoIeProductsExportAsync;
        if (!config || !config.ajaxUrl || !config.nonce) {
            return;
        }

        var complete = document.querySelector('button[name="seo_export_products"]');
        var reduced = document.querySelector('button[name="seo_export_products_reduced"]');
        var form = complete ? complete.closest('form') : null;
        if (!form || !reduced || reduced.closest('form') !== form) {
            return;
        }

        var panel = document.createElement('div');
        panel.className = 'notice notice-info inline';
        panel.style.cssText = 'margin:14px 0;padding:12px 16px;max-width:1100px;display:none';
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');

        var message = document.createElement('p');
        var controls = document.createElement('div');
        controls.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;align-items:center';
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'button';
        retry.textContent = 'Reintentar lote';
        retry.style.display = 'none';

        var download = document.createElement('a');
        download.className = 'button button-primary';
        download.textContent = 'Descargar CSV terminado';
        download.style.display = 'none';

        controls.appendChild(retry);
        controls.appendChild(download);
        panel.appendChild(message);
        panel.appendChild(controls);
        form.insertAdjacentElement('afterend', panel);

        var activeJob = '';
        var processing = false;

        async function request(action, fields) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', config.nonce);
            if (fields) {
                for (var pair of fields.entries()) {
                    body.append(pair[0], pair[1]);
                }
            }

            var response = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });

            var result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('Respuesta no válida del servidor (HTTP ' + response.status +
                    '). Revisa el registro PHP o los límites de tiempo de Webempresa.');
            }

            if (!response.ok || !result || !result.success) {
                throw new Error((result && result.data && result.data.message) ||
                    'Error de exportación HTTP ' + response.status);
            }
            return result.data || {};
        }

        function announce(text) {
            panel.style.display = '';
            message.textContent = text;
        }

        function showError(error) {
            announce('Exportación interrumpida: ' + (error.message || error));
            retry.style.display = activeJob ? '' : 'none';
        }

        function showDownload(url, processed) {
            processing = false;
            retry.style.display = 'none';
            if (!url) {
                announce('El proceso terminó sin enlace de descarga. Recarga la pantalla.');
                return;
            }
            announce('CSV generado: ' + processed + ' productos. Puedes descargar el inventario completo.');
            download.href = url;
            download.style.display = '';
            // Mantener el enlace visible incluso si el navegador móvil
            // impide iniciar automáticamente una descarga asincrónica.
            download.click();
        }

        async function processJob() {
            if (!activeJob || processing) {
                return;
            }
            processing = true;
            retry.style.display = 'none';
            complete.disabled = true;
            reduced.disabled = true;
            try {
                for (;;) {
                    var fields = new URLSearchParams();
                    fields.append('job', activeJob);
                    var data = await request('seo_ie_products_export_step', fields);
                    var processed = Number(data.processed || 0);
                    if (data.complete) {
                        showDownload(data.downloadUrl, processed);
                        return;
                    }
                    announce('Preparando CSV V2: ' + processed +
                        ' productos procesados. Mantén esta pestaña abierta hasta finalizar.');
                }
            } catch (error) {
                showError(error);
            } finally {
                processing = false;
                complete.disabled = false;
                reduced.disabled = false;
            }
        }

        retry.addEventListener('click', processJob);

        form.addEventListener('submit', async function (event) {
            var submitter = event.submitter;
            if (submitter !== complete && submitter !== reduced) {
                return;
            }
            event.preventDefault();
            if (processing) {
                return;
            }

            complete.disabled = true;
            reduced.disabled = true;
            download.style.display = 'none';
            retry.style.display = 'none';
            activeJob = '';
            announce('Iniciando exportación en lotes…');

            try {
                var fields = new FormData(form);
                fields.delete('seo_export_products');
                fields.delete('seo_export_products_reduced');
                fields.append('mode', submitter === complete ? 'full' : 'reduced');
                var result = await request('seo_ie_products_export_start', fields);
                activeJob = result.job;
                await processJob();
            } catch (error) {
                showError(error);
            } finally {
                complete.disabled = false;
                reduced.disabled = false;
            }
        });

        // Permite volver a la pantalla y seguir descargando un lote ya iniciado.
        request('seo_ie_products_export_status').then(function (data) {
            if (!data.active) {
                return;
            }
            if (data.complete) {
                activeJob = data.job;
                announce('CSV preparado con ' + data.processed + ' productos.');
                download.href = data.downloadUrl;
                download.style.display = '';
                return;
            }
            activeJob = data.job;
            announce('Reanudando exportación: ' + data.processed + ' productos procesados.');
            processJob();
        }).catch(function () {
            // No bloquear los botones si el diagnóstico de reanudación falla.
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());