/* WordPress/Gutenberg official menu extension and API delete interception. */
(function (wp) {
    'use strict';
    const cfg = window.tcRedirectsBlockEditor;
    if (!wp || !cfg || !cfg.confirmUrl) return;

    // The native Gutenberg trash action uses apiFetch. Route the current
    // object's deletion through the same confirmation form as the classic UI.
    if (wp.apiFetch && typeof wp.apiFetch.use === 'function') {
        wp.apiFetch.use(function (options, next) {
            const path = String(options.path || options.url || '');
            const method = String(options.method || 'GET').toUpperCase();
            const deleting = method === 'DELETE' || method === 'POST' && options.data && options.data.status === 'trash';
            const ids = cfg.restIds || [];
            const hits = ids.some(function (route) {
                const pos = path.indexOf(route);
                if (pos < 0) return false;
                const nextChar = path.charAt(pos + route.length);
                return nextChar === '' || nextChar === '/' || nextChar === '?' || nextChar === '#';
            });
            if (deleting && hits) {
                window.location.assign(cfg.confirmUrl);
                return Promise.reject(new Error('Se abre la confirmación de TaxoCommerce Redirects.'));
            }
            return next(options);
        });
    }

    if (!wp.plugins || !wp.element || !wp.editPost || !wp.editPost.PluginMoreMenuItem) {
        return;
    }
    const el = wp.element.createElement;
    function Menu() {
        return el(wp.editPost.PluginMoreMenuItem, {
            icon: 'trash',
            onClick: function () { window.location.assign(cfg.confirmUrl); }
        }, 'Eliminar con Redirects');
    }
    wp.plugins.registerPlugin('taxocommerce-redirects-assisted-delete', { render: Menu });
}(window.wp));
