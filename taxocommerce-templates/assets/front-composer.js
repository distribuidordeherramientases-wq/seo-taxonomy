/* FRONT composer pilot. Administrative draft only, never edits frontend templates. */
(function ($) {
    'use strict';
    $(function () {
        if (typeof TCTFrontComposer === 'undefined') { return; }
        var source = TCTFrontComposer;
        var catalog = source.catalog || {};
        var keys = Object.keys(catalog);
        var configs = source.configs || {};
        var labels = source.labels || {};
        var form = document.getElementById('tct-front-composer-form');
        var marketInput = document.getElementById('tct-front-market');
        var nameInput = document.getElementById('tct-front-name');
        var available = document.getElementById('tct-front-available-list');
        var selected = document.getElementById('tct-front-selected-list');
        if (!form || !marketInput || !nameInput || !available || !selected) { return; }

        var market = marketInput.value;
        var device = 'desktop';
        var dirty = false;
        var state = { desktop: [], mobile: [] };

        function copyItems(rows) {
            return Array.isArray(rows) ? rows.map(function (row) {
                return { id: String(row.id), enabled: row.enabled === true, title: String(row.title || '') };
            }).filter(function (row, i, list) {
                return keys.indexOf(row.id) >= 0 && list.findIndex(function (x) { return x.id === row.id; }) === i;
            }) : [];
        }
        function load() {
            var config = configs[market] || {};
            nameInput.value = config.name || ('FRONT · ' + market);
            state.desktop = copyItems(config.desktop);
            state.mobile = copyItems(config.mobile);
            dirty = false;
            draw();
        }
        function node(tag, className, value) {
            var el = document.createElement(tag);
            if (className) { el.className = className; }
            if (typeof value !== 'undefined') { el.textContent = value; }
            return el;
        }
        function button(label, className, action, title) {
            var btn = node('button', className, label);
            btn.type = 'button';
            btn.setAttribute('aria-label', title || label);
            btn.addEventListener('click', action);
            return btn;
        }
        function add(id) {
            if (!catalog[id] || state[device].some(function (x) { return x.id === id; })) { return; }
            state[device].push({ id: id, enabled: true, title: '' });
            dirty = true;
            draw();
        }
        function remove(id) {
            state[device] = state[device].filter(function (x) { return x.id !== id; });
            dirty = true;
            draw();
        }
        function move(id, change) {
            var arr = state[device];
            var pos = arr.findIndex(function (x) { return x.id === id; });
            var next = pos + change;
            if (pos < 0 || next < 0 || next >= arr.length) { return; }
            var item = arr.splice(pos, 1)[0];
            arr.splice(next, 0, item);
            dirty = true;
            draw();
        }
        function draw() {
            var $selected = $(selected);
            if ($selected.data('ui-sortable')) { $selected.sortable('destroy'); }
            available.replaceChildren();
            selected.replaceChildren();

            keys.forEach(function (id) {
                if (state[device].some(function (item) { return item.id === id; })) { return; }
                var li = node('li', 'tct-front-catalog-item');
                li.draggable = true;
                li.dataset.module = id;
                li.appendChild(node('span', 'tct-front-module-label', catalog[id]));
                li.appendChild(button(labels.add || 'Añadir', 'button button-small', function () { add(id); }, (labels.add || 'Añadir') + ' ' + catalog[id]));
                li.addEventListener('dragstart', function (event) {
                    event.dataTransfer.setData('text/plain', id);
                    event.dataTransfer.effectAllowed = 'copy';
                });
                available.appendChild(li);
            });
            state[device].forEach(function (item, index) {
                var li = node('li', 'tct-front-selected-item');
                li.dataset.module = item.id;
                if (!item.enabled) { li.classList.add('tct-front-disabled'); }
                var row = node('div', 'tct-front-selected-row');
                var handle = node('span', 'tct-front-drag', '≡');
                handle.setAttribute('title', 'Arrastrar para ordenar');
                row.appendChild(handle);
                row.appendChild(node('strong', 'tct-front-module-label', catalog[item.id]));
                row.appendChild(button('↑', 'button button-small', function () { move(item.id, -1); }, (labels.up || 'Subir') + ' ' + catalog[item.id]));
                row.appendChild(button('↓', 'button button-small', function () { move(item.id, 1); }, (labels.down || 'Bajar') + ' ' + catalog[item.id]));
                row.appendChild(button('×', 'button button-small', function () { remove(item.id); }, (labels.remove || 'Quitar') + ' ' + catalog[item.id]));
                li.appendChild(row);

                var details = node('details', 'tct-front-module-config');
                details.appendChild(node('summary', '', labels.configure || 'Configurar'));
                var toggleLabel = node('label', 'tct-front-check');
                var visible = node('input');
                visible.type = 'checkbox';
                visible.checked = item.enabled;
                visible.addEventListener('change', function () {
                    item.enabled = visible.checked;
                    li.classList.toggle('tct-front-disabled', !visible.checked);
                    dirty = true;
                });
                toggleLabel.appendChild(visible);
                toggleLabel.appendChild(document.createTextNode(' ' + (labels.visible || 'Visible')));
                details.appendChild(toggleLabel);
                var textLabel = node('label', 'tct-front-title-label', labels.title || 'Título opcional del módulo');
                var title = node('input');
                title.type = 'text';
                title.maxLength = 100;
                title.value = item.title || '';
                title.addEventListener('input', function () { item.title = title.value; dirty = true; });
                textLabel.appendChild(title);
                details.appendChild(textLabel);
                li.appendChild(details);
                selected.appendChild(li);
            });

            $selected.sortable({
                items: '> li',
                handle: '.tct-front-drag',
                axis: 'y',
                update: function () {
                    var ordered = Array.from(selected.children).map(function (li) {
                        return state[device].find(function (item) { return item.id === li.dataset.module; });
                    }).filter(Boolean);
                    if (ordered.length === state[device].length) {
                        state[device] = ordered;
                        dirty = true;
                    }
                }
            });
            document.querySelectorAll('[data-tct-device]').forEach(function (btn) {
                var active = btn.dataset.tctDevice === device;
                btn.setAttribute('aria-pressed', String(active));
                btn.classList.toggle('button-primary', active);
            });
        }
        selected.addEventListener('dragover', function (event) { event.preventDefault(); });
        selected.addEventListener('drop', function (event) {
            var id = event.dataTransfer.getData('text/plain');
            if (catalog[id]) { event.preventDefault(); add(id); }
        });
        document.querySelectorAll('[data-tct-device]').forEach(function (btn) {
            btn.addEventListener('click', function () { device = btn.dataset.tctDevice; draw(); });
        });
        marketInput.addEventListener('change', function () {
            if (dirty && !window.confirm(labels.unsaved || '¿Descartar los cambios sin guardar?')) {
                marketInput.value = market;
                return;
            }
            market = marketInput.value;
            load();
        });
        nameInput.addEventListener('input', function () { dirty = true; });
        form.addEventListener('submit', function () {
            document.getElementById('tct-front-desktop-json').value = JSON.stringify(state.desktop);
            document.getElementById('tct-front-mobile-json').value = JSON.stringify(state.mobile);
        });
        load();
    });
}(jQuery));
