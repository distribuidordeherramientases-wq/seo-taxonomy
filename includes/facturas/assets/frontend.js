(function () {
    'use strict';

    function initBox(box) {
        var toggle = box.querySelector('[data-seo-quote-toggle]');
        var panel = box.querySelector('[data-seo-quote-panel]');
        if (!toggle || !panel) return;

        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            panel.hidden = open;
            if (!open) {
                var first = panel.querySelector('input:not([type="hidden"])');
                if (first) first.focus();
            }
        });

        if (box.querySelector('.woocommerce-error')) {
            toggle.setAttribute('aria-expanded', 'true');
            panel.hidden = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-seo-quote-box]').forEach(initBox);
    });
}());
