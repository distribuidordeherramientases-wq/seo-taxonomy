/* TaxoCommerce SEO: batch-import admin upload limits and progress polling. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const config = window.taxocommerceBatchUIConfig || {};
        const input = document.querySelector('input[name="seo_ie_batch_files[]"]');
        if (input) {
            input.addEventListener('change', function () {
                const maxFiles = Math.max(1, Number(config.maxFiles) || 20);
                if (this.files && this.files.length > maxFiles) {
                    window.alert('El servidor admite como maximo ' + maxFiles + ' archivos por seleccion. Divide la subida en varios grupos.');
                    this.value = '';
                }
            });
        }

        if (!config.running || !config.ajaxUrl || !config.nonce) {
            return;
        }
        window.setInterval(function () {
            const body = new URLSearchParams();
            body.set('action', 'seo_ie_batch_tick');
            body.set('nonce', config.nonce);
            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function () { window.location.reload(); })
                .catch(function () {});
        }, 10000);
    });
})();
