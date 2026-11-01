/* global tcCoreEditorSettings */
(function () {
    'use strict';

    const config = window.tcCoreEditorSettings || {};
    if (!config.url || !config.nonce) {
        return;
    }

    const toText = (value) => String(value === null || value === undefined ? '' : value);
    const pickers = document.querySelectorAll('[data-tc-picker]');
    const addPickerChip = (picker, id, label) => {
        id = String(Number.parseInt(id, 10) || 0);
        if (id === '0') {
            return;
        }
        const chips = picker.querySelector('[data-tc-chips]');
        if (!chips) {
            return;
        }
        if (picker.dataset.single === '1') {
            chips.textContent = '';
        }
        if (chips.querySelector('[data-tc-term-id="' + id + '"]')) {
            return;
        }
        const chip = document.createElement('span');
        chip.className = 'tc-term-chip';
        chip.dataset.tcTermId = id;

        const text = document.createTextNode(toText(label) + ' ');
        chip.appendChild(text);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'tc-chip-remove';
        remove.dataset.tcRemove = '';
        remove.setAttribute('aria-label', ((config.strings && config.strings.remove) || 'Quitar %s').replace('%s', toText(label)));
        remove.textContent = '×';
        chip.appendChild(remove);

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = picker.dataset.field || '';
        input.value = id;
        input.dataset.tcValue = '';
        chip.appendChild(input);
        chips.appendChild(chip);
    };

    function initPicker(picker) {
        if (!picker || picker.dataset.tcInit === '1') {
            return;
        }
        picker.dataset.tcInit = '1';
        const input = picker.querySelector('[data-tc-search]');
        const list = picker.querySelector('[data-tc-results]');
        if (!input || !list) {
            return;
        }
        let debounce = null;
        let controller = null;

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }

        function message(text) {
            list.replaceChildren();
            const line = document.createElement('div');
            line.className = 'tc-picker-empty';
            line.textContent = toText(text);
            list.appendChild(line);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function choose(id, label) {
            addPickerChip(picker, id, label);
            input.value = '';
            close();
            input.focus();
        }

        async function search() {
            const group = picker.dataset.group || '';
            if (!group) {
                message((config.strings && config.strings.choose) || 'Elige un atributo.');
                return;
            }
            if (controller) {
                controller.abort();
            }
            controller = new AbortController();
            const request = new URLSearchParams();
            request.set('action', 'taxocommerce_core_editor_terms');
            request.set('nonce', config.nonce);
            request.set('mode', picker.dataset.mode || '');
            request.set('group', group);
            request.set('search', input.value.trim());
            message((config.strings && config.strings.loading) || 'Buscando…');

            try {
                const response = await fetch(config.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: request.toString(),
                    credentials: 'same-origin',
                    signal: controller.signal
                });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                const data = await response.json();
                if (!data || !data.success || !Array.isArray(data.data)) {
                    throw new Error('Respuesta no válida.');
                }
                list.replaceChildren();
                const selected = new Set(
                    Array.from(picker.querySelectorAll('[data-tc-term-id]'), chip => chip.dataset.tcTermId)
                );
                const available = data.data.filter(term => !selected.has(String(term.id)));
                if (!available.length) {
                    message((config.strings && config.strings.none) || 'No hay resultados.');
                    return;
                }
                available.forEach(term => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'tc-picker-option';
                    option.setAttribute('role', 'option');
                    option.textContent = toText(term.label);
                    option.addEventListener('click', () => choose(term.id, term.label));
                    list.appendChild(option);
                });
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            } catch (error) {
                if (error.name !== 'AbortError') {
                    message((config.strings && config.strings.error) || 'Error al buscar.');
                }
            }
        }

        function scheduleSearch() {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(search, 240);
        }
        input.addEventListener('focus', scheduleSearch);
        input.addEventListener('input', scheduleSearch);
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                close();
            }
            if (event.key === 'Enter' && !list.hidden) {
                const first = list.querySelector('.tc-picker-option');
                if (first) {
                    event.preventDefault();
                    first.click();
                }
            }
        });
        input.addEventListener('blur', () => window.setTimeout(close, 180));
        picker.addEventListener('click', event => {
            const button = event.target.closest('[data-tc-remove]');
            if (button && picker.contains(button)) {
                const chip = button.closest('.tc-term-chip');
                if (chip) {
                    chip.remove();
                }
            }
        });

        const select = picker.closest('[data-tc-attribute-row]')?.querySelector('[data-tc-taxonomy-select]');
        if (select) {
            select.addEventListener('change', () => {
                picker.dataset.group = select.value;
                picker.querySelector('[data-tc-chips]')?.replaceChildren();
                input.value = '';
                close();
            });
            picker.dataset.group = select.value;
        }
    }

    pickers.forEach(initPicker);

    const list = document.querySelector('[data-tc-attribute-list]');
    if (list) {
        let nextIndex = list.querySelectorAll('[data-tc-attribute-row]').length;
        document.querySelectorAll('[data-tc-add-attribute]').forEach(button => {
            button.addEventListener('click', () => {
                const kind = button.dataset.tcAddAttribute;
                const template = document.querySelector('#tc-template-' + kind + '-attribute');
                if (!template) {
                    return;
                }
                const html = template.innerHTML.replace(/__index__/g, String(nextIndex++));
                const container = document.createElement('div');
                container.innerHTML = html;
                const row = container.firstElementChild;
                if (!row) {
                    return;
                }
                list.appendChild(row);
                row.querySelectorAll('[data-tc-picker]').forEach(initPicker);
                row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });
        list.addEventListener('click', event => {
            const button = event.target.closest('[data-tc-remove-attribute]');
            if (button) {
                button.closest('[data-tc-attribute-row]')?.remove();
            }
        });
    }

    document.querySelectorAll('[data-tc-media-select]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            if (!window.wp || !window.wp.media) {
                return;
            }
            const card = button.closest('.tc-card-image');
            const input = card && card.querySelector('[data-tc-image-id]');
            const preview = card && card.querySelector('[data-tc-image-preview]');
            if (!input || !preview) {
                return;
            }
            const frame = window.wp.media({
                title: (config.strings && config.strings.mediaTitle) || 'Elegir imagen',
                button: { text: (config.strings && config.strings.mediaButton) || 'Utilizar imagen' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', () => {
                const image = frame.state().get('selection').first()?.toJSON();
                if (!image || !image.id) {
                    return;
                }
                input.value = image.id;
                const picture = document.createElement('img');
                picture.src = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
                picture.alt = toText(image.alt || image.title || 'Imagen seleccionada');
                preview.replaceChildren(picture);
            });
            frame.open();
        });
    });

    document.querySelectorAll('[data-tc-media-remove]').forEach(button => {
        button.addEventListener('click', () => {
            const card = button.closest('.tc-card-image');
            const input = card && card.querySelector('[data-tc-image-id]');
            const preview = card && card.querySelector('[data-tc-image-preview]');
            if (!input || !preview) {
                return;
            }
            input.value = '0';
            const empty = document.createElement('div');
            empty.className = 'tc-image-empty';
            empty.textContent = (config.strings && config.strings.imageEmpty) || 'Sin imagen destacada';
            preview.replaceChildren(empty);
        });
    });

    const form = document.querySelector('.tc-editor-form');
    if (form) {
        form.addEventListener('submit', () => {
            if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
                window.tinyMCE.triggerSave();
            }
        });
    }
}());
