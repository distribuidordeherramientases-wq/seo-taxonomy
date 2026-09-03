(function () {
    'use strict';

    function initBox(box) {
        var panel = box.querySelector('[data-seo-quote-panel]');
        var kindInput = box.querySelector('[data-seo-doc-kind]');
        var title = box.querySelector('[data-seo-doc-title]');
        var help = box.querySelector('[data-seo-doc-help]');
        var submit = box.querySelector('[data-seo-doc-submit]');
        var buttons = box.querySelectorAll('[data-seo-doc-open]');

        if (!panel || !kindInput || !buttons.length) return;

        function activate(button) {
            var kind = button.getAttribute('data-seo-doc-open') || 'quote';
            kindInput.value = kind;

            buttons.forEach(function (item) {
                item.setAttribute('aria-expanded', item === button ? 'true' : 'false');
                item.classList.toggle('is-active', item === button);
            });

            if (title) title.textContent = button.getAttribute('data-title') || 'Preparar documento';
            if (help) help.textContent = button.getAttribute('data-help') || '';
            if (submit) submit.textContent = button.getAttribute('data-submit') || 'Descargar PDF';

            panel.hidden = false;
            var first = panel.querySelector('input:not([type="hidden"])');
            if (first) first.focus();
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button);
            });
        });

        var current = kindInput.value || 'quote';
        var initial = box.querySelector('[data-seo-doc-open="' + current + '"]') || buttons[0];

        if (box.querySelector('.woocommerce-error')) {
            activate(initial);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-seo-quote-box]').forEach(initBox);
    });
}());
