document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.seo-menu-metabox__title').forEach(function (button) {
            button.addEventListener('click', function () {
                var box = button.closest('.seo-menu-metabox');
                var open = box.classList.toggle('is-open');
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        document.querySelectorAll('.seo-menu-metabox').forEach(function (box) {
            box.querySelectorAll('.seo-menu-tabs button').forEach(function (tabButton) {
                tabButton.addEventListener('click', function () {
                    var tab = tabButton.getAttribute('data-tab');

                    box.querySelectorAll('.seo-menu-tabs button').forEach(function (button) {
                        button.classList.toggle('is-active', button === tabButton);
                    });

                    box.querySelectorAll('.seo-menu-tab-panel').forEach(function (panel) {
                        panel.classList.toggle(
                            'is-active',
                            panel.getAttribute('data-panel') === tab
                        );
                    });
                });
            });

            var search = box.querySelector('.seo-menu-live-search');

            if (search) {
                search.addEventListener('input', function () {
                    var query = search.value.toLowerCase().trim();

                    box.querySelectorAll('.seo-menu-search-list li').forEach(function (row) {
                        var label = (row.getAttribute('data-label') || '').toLowerCase();
                        row.style.display = label.indexOf(query) !== -1 ? '' : 'none';
                    });
                });
            }

            var selectAll = box.querySelector('.seo-menu-select-all');

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    var activePanel = box.querySelector('.seo-menu-tab-panel.is-active');

                    if (!activePanel) {
                        return;
                    }

                    activePanel.querySelectorAll('input[type="checkbox"][name="seo_object_ids[]"]').forEach(function (checkbox) {
                        if (checkbox.closest('li').style.display !== 'none') {
                            checkbox.checked = selectAll.checked;
                        }
                    });
                });
            }
        });

        document.querySelectorAll('.seo-menu-manual-item__bar').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = button.closest('.seo-menu-manual-item');
                var open = item.classList.toggle('is-open');
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    });
