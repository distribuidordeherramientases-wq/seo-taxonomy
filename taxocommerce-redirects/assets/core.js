/* Add assisted-delete controls to TaxoCommerce Core without modifying Core. */
(function () {
    'use strict';
    const cfg = window.tcRedirectsCore;
    if (!cfg || !cfg.confirmBase || !cfg.typeMap) return;
    const search = new URLSearchParams(window.location.search);
    if (search.get('page') !== 'taxocommerce-core-content') return;
    const type = cfg.typeMap[search.get('tc_type') || 'products'];
    if (!type) return;

    const confirmUrl = (id) => cfg.confirmBase + '&tc_type=' + encodeURIComponent(type) +
        '&tc_id=' + encodeURIComponent(id);

    function link(id) {
        const a = document.createElement('a');
        a.href = confirmUrl(id);
        a.className = 'button button-link-delete tc-rd-core-delete';
        a.textContent = 'Eliminar con Redirects';
        return a;
    }

    function prepare() {
        const id = Number.parseInt(search.get('item_id') || '0', 10);
        if (search.get('tc_action') === 'edit' && id > 0) {
            const actions = document.querySelector('.tc-editor-top-actions');
            if (actions && !actions.querySelector('.tc-rd-core-delete')) {
                actions.appendChild(link(id));
            }
            const form = document.querySelector('form.tc-editor-form');
            if (form) {
                form.addEventListener('submit', function (event) {
                    const status = form.querySelector('select[name="status"]');
                    if (status && status.value === 'trash') {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.location.assign(confirmUrl(id));
                    }
                }, true);
            }
        }

        document.querySelectorAll('.tc-overview-table tbody tr').forEach(function (row) {
            const actions = row.querySelector('td:last-child');
            const identifier = row.querySelector('td:first-child small');
            if (!actions || !identifier || actions.querySelector('.tc-rd-core-delete')) return;
            const match = identifier.textContent.match(/#\s*(\d+)/);
            if (!match) return;
            const elem = link(Number.parseInt(match[1], 10));
            elem.className = 'tc-rd-core-delete';
            actions.appendChild(document.createTextNode(' · '));
            actions.appendChild(elem);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prepare, { once: true });
    } else {
        prepare();
    }
}());
