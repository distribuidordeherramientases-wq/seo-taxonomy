/* global wp */
(function () {
    'use strict';

    if (!window.wp || !wp.media) {
        return;
    }

    document.querySelectorAll('[data-tci-media-picker]').forEach(function (form) {
        var openButton = form.querySelector('[data-tci-media-open]');
        var submitButton = form.querySelector('[data-tci-media-submit]');
        var input = form.querySelector('[data-tci-media-id]');
        var selection = form.querySelector('[data-tci-media-selection]');
        var frame = null;

        if (!openButton || !submitButton || !input || !selection) {
            return;
        }

        openButton.addEventListener('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Seleccionar imagen de Media',
                button: { text: 'Usar esta imagen' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var model = frame.state().get('selection').first();
                if (!model) {
                    return;
                }

                var attachment = model.toJSON();
                var preview = document.createElement('span');
                var image = document.createElement('img');
                var label = document.createElement('span');
                var url = attachment.url;

                if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                    url = attachment.sizes.thumbnail.url;
                }

                input.value = attachment.id || '';
                selection.textContent = '';

                preview.className = 'tci-assign-media-preview';
                image.src = url || '';
                image.alt = '';
                image.loading = 'lazy';
                label.textContent = (attachment.title || attachment.filename || 'Imagen de Media') + ' #' + (attachment.id || '');

                preview.appendChild(image);
                preview.appendChild(label);
                selection.appendChild(preview);
                submitButton.disabled = !input.value;
            });

            frame.open();
        });
    });
}());
