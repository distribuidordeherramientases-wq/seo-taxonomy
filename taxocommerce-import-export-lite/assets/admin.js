(function () {
    'use strict';
    var form = document.getElementById('tciel-import-form');
    if (!form || typeof tcielWizard === 'undefined') return;
    var progress = document.getElementById('tciel-progress');
    var errors = document.getElementById('tciel-errors');
    var start = form.querySelector('input[type="submit"], button[type="submit"]');
    var running = false;
    var ready = false;
    var completed = false;
    function enable() {
        form.querySelectorAll('button,input').forEach(function (el) { el.disabled = false; });
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
                progress.textContent = label + ' ' + tcielWizard.messages.rows + ' ' + count + ' · ' + tcielWizard.messages.saved + ' ' + data.saved + ' · ' + tcielWizard.messages.errors + ' ' + data.errors + (data.done ? ' · ' + tcielWizard.messages.done : '');
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
                // Correct an invalid CSV and load it again in its content block.
                ready = false;
                enable();
                if (start) start.value = tcielWizard.messages.validate;
            });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (running || completed) return;
        var payload = new FormData(form);
        payload.append('action', 'tciel_import_step');
        payload.append('nonce', tcielWizard.nonce);
        payload.append('confirmed', ready ? '1' : '0');
        running = true;
        form.querySelectorAll('button,input').forEach(function (el) { el.disabled = true; });
        progress.textContent = ready ? tcielWizard.messages.running : tcielWizard.messages.checking;
        send(payload).catch(function (err) {
            enable();
            progress.textContent = (err && err.message) || tcielWizard.messages.error;
        });
    });
})();
