document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('seo-direct-category-move-form');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            const rawIds = document
                .getElementById('direct_product_ids')
                .value
                .trim();

            const categoryId = document
                .getElementById('direct_target_category_id')
                .value
                .trim();

            const ids = rawIds
                .split(/[\s,;]+/)
                .filter(Boolean);

            if (!ids.length || !categoryId) {
                return;
            }

            const confirmed = window.confirm(
                'Se moverán ' + ids.length +
                ' productos a la categoría ID ' + categoryId + '.\n\n' +
                'Las categorías actuales serán sustituidas.\n\n' +
                '¿Quieres continuar?'
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });
