(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('tcs-code-next');
        if (!form) {
            return;
        }
        var delay = parseInt(form.getAttribute('data-delay'), 10);
        if (!Number.isFinite(delay)) {
            delay = 2000;
        }
        // Never run concurrent requests. Each POST checks a limited file batch.
        var timer = window.setTimeout(function () {
            form.submit();
        }, Math.max(800, Math.min(5000, delay)));
        Array.prototype.forEach.call(document.querySelectorAll('.tcs-code-check form'), function (control) {
            if (control === form) {
                return;
            }
            control.addEventListener('submit', function () {
                window.clearTimeout(timer);
            });
        });
    });
}());