(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('tcs-family-next');
        if (!form) {
            return;
        }
        var delay = parseInt(form.getAttribute('data-delay'), 10);
        if (!Number.isFinite(delay)) {
            delay = 3000;
        }
        // One bounded batch per request, never parallel AJAX workers.
        var task = window.setTimeout(function () {
            form.submit();
        }, Math.max(800, Math.min(5000, delay)));
        var pause = document.querySelector('[data-tcs-pause]');
        if (pause) {
            pause.addEventListener('submit', function () {
                window.clearTimeout(task);
            });
        }
    });
}());