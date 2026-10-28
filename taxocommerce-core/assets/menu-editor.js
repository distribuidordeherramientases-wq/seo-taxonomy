/* Native menu additions; no front-end scripts or monolith dependency. */
(function () {
    'use strict';
    if (typeof tcMenuEditor === 'undefined') { return; }
    document.querySelectorAll('.tc-menu-picker').forEach(function (form) {
        var list = form.querySelector('.tc-menu-checklist');
        var pool = form.querySelector('.tc-menu-selection');
        var search = form.querySelector('.tc-menu-search');
        var pager = form.querySelector('.tc-menu-pages');
        var selectAll = form.querySelector('[data-select-all]');
        var selected = new Map();
        var mode = 'recent', page = 1, more = false, timer, controller, sequence = 0;
        list.querySelectorAll('input[type=checkbox]').forEach(function (box) { box.removeAttribute('name'); });
        function saveSelection() {
            pool.replaceChildren();
            selected.forEach(function (_, id) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = 'object_ids[]'; hidden.value = id; pool.appendChild(hidden);
            });
            var boxes = Array.from(list.querySelectorAll('input[type=checkbox]'));
            var count = boxes.filter(function (box) { return box.checked; }).length;
            selectAll.checked = boxes.length > 0 && boxes.length === count;
            selectAll.indeterminate = count > 0 && count < boxes.length;
        }
        list.addEventListener('change', function (event) {
            var box = event.target;
            if (box.matches('input[type=checkbox]')) {
                if (box.checked) { selected.set(box.value, true); } else { selected.delete(box.value); }
                saveSelection();
            }
        });
        selectAll.addEventListener('change', function () {
            list.querySelectorAll('input[type=checkbox]').forEach(function (box) {
                box.checked = selectAll.checked;
                if (box.checked) { selected.set(box.value, true); } else { selected.delete(box.value); }
            });
            saveSelection();
        });
        function load() {
            if (controller) { controller.abort(); }
            controller = new AbortController();
            var current = ++sequence;
            list.textContent = tcMenuEditor.loading;
            list.setAttribute('aria-busy', 'true'); pager.hidden = true;
            var params = new URLSearchParams({action: tcMenuEditor.action, nonce: tcMenuEditor.nonce,
                type: form.dataset.type, tc_country: tcMenuEditor.country, mode: mode, list_page: String(page), needle: search.querySelector('input').value});
            fetch(tcMenuEditor.url, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body: params.toString(), signal: controller.signal})
                .then(function (response) { if (!response.ok) { throw new Error('HTTP'); } return response.json(); })
                .then(function (response) {
                    if (current !== sequence) { return; }
                    if (!response.success || !response.data || !Array.isArray(response.data.items)) { throw new Error('Response'); }
                    list.replaceChildren();
                    response.data.items.forEach(function (item) {
                        var label = document.createElement('label'); var box = document.createElement('input');
                        box.type = 'checkbox'; box.value = String(item.id); box.checked = selected.has(box.value);
                        label.appendChild(box); label.appendChild(document.createTextNode(' ' + item.title)); list.appendChild(label);
                    });
                    if (!response.data.items.length) { list.textContent = tcMenuEditor.empty; }
                    more = !!response.data.more;
                    pager.hidden = mode === 'recent';
                    pager.querySelector('span').textContent = tcMenuEditor.page + ' ' + page;
                    pager.querySelector('[data-direction="-1"]').disabled = page <= 1;
                    pager.querySelector('[data-direction="1"]').disabled = !more;
                    saveSelection();
                })
                .catch(function (error) { if (error.name !== 'AbortError' && current === sequence) { list.textContent = tcMenuEditor.error; } })
                .finally(function () { if (current === sequence) { list.removeAttribute('aria-busy'); } });
        }
        form.querySelectorAll('[data-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                mode = button.dataset.mode; page = 1;
                form.querySelectorAll('[data-mode]').forEach(function (tab) { tab.setAttribute('aria-pressed', tab === button ? 'true' : 'false'); });
                search.hidden = mode !== 'search';
                if (mode === 'search') { search.querySelector('input').focus(); }
                load();
            });
        });
        search.querySelector('input').addEventListener('input', function () { clearTimeout(timer); page = 1; timer = setTimeout(load, 250); });
        search.querySelector('input').addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); page = 1; load(); } });
        pager.querySelectorAll('button').forEach(function (button) { button.addEventListener('click', function () { page = Math.max(1, page + Number(button.dataset.direction)); load(); }); });
    });
    document.querySelectorAll('[data-tc-menu-location]').forEach(function (select) {
        select.addEventListener('change', function () {
            document.querySelectorAll('[data-tc-menu-location]').forEach(function (other) { other.value = select.value; });
        });
    });
    document.querySelectorAll('[data-tc-menu-remove]').forEach(function (form) {
        form.addEventListener('submit', function (event) { if (!window.confirm(tcMenuEditor.remove)) { event.preventDefault(); } });
    });
}());
