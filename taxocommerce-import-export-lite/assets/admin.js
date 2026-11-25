(function () {
    'use strict';
    var exportType = document.querySelector('.tciel-field-group') && document.querySelector('.tciel-admin #tciel-type');
    if (exportType && document.querySelector('.tciel-field-group')) {
        function showFields() {
            document.querySelectorAll('.tciel-field-group').forEach(function (el) {
                el.hidden = el.dataset.type !== exportType.value;
            });
        }
        exportType.addEventListener('change', showFields);
        showFields();
    }
    var form = document.getElementById('tciel-import-form');
    var definitions = document.getElementById('tciel-fields');
    if (!form || !definitions || typeof tcielWizard === 'undefined') return;
    var fields = JSON.parse(definitions.textContent);
    var type = document.getElementById('tciel-type');
    var maps = Array.prototype.slice.call(form.querySelectorAll('.tciel-field-map'));
    var aliases = {
        product: {product_name: 'name', nombre: 'name', product_type: 'type', published: 'status', publicado: 'status', precio: 'regular_price', precio_normal: 'regular_price', precio_rebajado: 'sale_price', descripcion: 'description', descripcion_corta: 'short_description', download_expiry_days: 'download_expiry'},
        post: {post_title: 'title', post_content: 'content', titulo: 'title'},
        page: {post_title: 'title', post_content: 'content', titulo: 'title'}
    };
    function normalize(s) {
        return (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    }
    function updateMap() {
        var allowed = fields[type.value] || {};
        maps.forEach(function (el) {
            var prior = el.value;
            var original = (el.dataset.column || '').trim();
            var key = normalize(original);
            var suggestion = ((aliases[type.value] || {})[key] || key);
            // Only auto-map a dynamic field when its header is an exact exported
            // field identifier. An unrelated shop's column is not enough.
            if ((original.indexOf('meta:') === 0 || original.indexOf('attr:') === 0) && allowed[original]) {
                suggestion = original;
            }
            if (['id', 'categories', 'tags', 'parent_slug', 'featured_image'].indexOf(suggestion) >= 0) suggestion = '';
            el.replaceChildren();
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'No importar';
            el.appendChild(none);
            Object.keys(allowed).forEach(function (field) {
                var option = document.createElement('option');
                option.value = field;
                option.textContent = allowed[field];
                el.appendChild(option);
            });
            el.value = allowed[prior] ? prior : (allowed[suggestion] ? suggestion : '');
        });
    }
    var progress = document.getElementById('tciel-progress');
    var errors = document.getElementById('tciel-errors');
    var start = form.querySelector('input[type="submit"], button[type="submit"]');
    var running = false;
    var ready = false;
    var completed = false;
    function resetReady() {
        if (!running && !completed) {
            ready = false;
            if (start) start.value = tcielWizard.messages.validate;
        }
    }
    form.addEventListener('change', resetReady);
    type.addEventListener('change', updateMap);
    updateMap();
    function enable() {
        form.querySelectorAll('button,input,select').forEach(function (el) { el.disabled = false; });
        running = false;
    }
    function send(payload) {
        return fetch(tcielWizard.endpoint, { method: 'POST', credentials: 'same-origin', body: payload })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                if (!response.success) throw new Error(response.data && response.data.message || tcielWizard.messages.error);
                var data = response.data;
                var phase = data.phase || '';
                var count = phase === 'checking' || phase === 'invalid' || phase === 'ready' ? data.checked : data.processed;
                var label = phase === 'checking' ? tcielWizard.messages.checking : (phase === 'ready' ? tcielWizard.messages.ready : (phase === 'invalid' ? tcielWizard.messages.invalid : tcielWizard.messages.running));
                progress.textContent = label + ' ' + count + ' filas · ' + data.saved + ' guardadas · ' + data.errors + ' errores' + (data.done ? ' · ' + tcielWizard.messages.done : '');
                errors.textContent = (data.notices || []).join('\n');
                if (phase === 'checking' || phase === 'importing') {
                    var next = new FormData();
                    next.append('action', 'tciel_import_step');
                    next.append('nonce', tcielWizard.nonce);
                    next.append('token', form.querySelector('[name="token"]').value);
                    return send(next);
                }
                if (phase === 'ready') {
                    ready = true;
                    enable();
                    if (start) start.value = tcielWizard.messages.confirm;
                    return;
                }
                if (phase === 'done') {
                    completed = true;
                    enable();
                    if (start) start.disabled = true;
                    return;
                }
                // Invalid rows are corrected in the CSV, or the mapping is changed
                // and the user runs the read-only preflight again.
                ready = false;
                enable();
                if (start) start.value = tcielWizard.messages.validate;
            });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (running || completed) return;
        var assigned = maps.map(function (el) { return el.value; }).filter(Boolean);
        if (new Set(assigned).size !== assigned.length) {
            progress.textContent = tcielWizard.messages.duplicate;
            return;
        }
        var payload = new FormData(form);
        payload.append('action', 'tciel_import_step');
        payload.append('nonce', tcielWizard.nonce);
        payload.append('confirmed', ready ? '1' : '0');
        running = true;
        form.querySelectorAll('button,input,select').forEach(function (el) { el.disabled = true; });
        progress.textContent = ready ? tcielWizard.messages.running : tcielWizard.messages.checking;
        send(payload).catch(function (err) {
            enable();
            progress.textContent = (err && err.message) || tcielWizard.messages.error;
        });
    });
})();
