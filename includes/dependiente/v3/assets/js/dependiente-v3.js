(function () {
    'use strict';

    const config = window.SEO_DEPENDIENTE_V3 || {};
    const esc = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

    document.querySelectorAll('[data-dep3-root]').forEach(initRoot);

    function initRoot(root) {
        const form = root.querySelector('[data-dep3-form]');
        const input = root.querySelector('[data-dep3-query]');
        const status = root.querySelector('[data-dep3-status]');
        const workspace = root.querySelector('[data-dep3-workspace]');
        const categoriesSection = root.querySelector('[data-dep3-categories-section]');
        const categories = root.querySelector('[data-dep3-categories]');
        const guidanceCopy = root.querySelector('[data-dep3-guidance-copy]');
        const productsSection = root.querySelector('[data-dep3-products-section]');
        const products = root.querySelector('[data-dep3-products]');
        const pagination = root.querySelector('[data-dep3-pagination]');
        const debugPanel = root.querySelector('[data-dep3-debug]');
        const debugContent = root.querySelector('[data-dep3-debug-content]');
        const clearCategory = root.querySelector('[data-dep3-clear-category]');

        let currentQuery = '';
        let currentCategory = '';
        let controller = null;

        if (config.showDebug && debugPanel) debugPanel.hidden = false;

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const query = input.value.trim();
            if (!query) return;
            currentQuery = query;
            currentCategory = '';
            runSearch(1);
        });

        if (clearCategory) {
            clearCategory.addEventListener('click', function () {
                currentCategory = '';
                runSearch(1);
            });
        }

        categories.addEventListener('click', function (event) {
            const button = event.target.closest('[data-dep3-category]');
            if (!button) return;
            const selected = button.getAttribute('data-dep3-category') || '';
            if (!selected) return;
            currentCategory = selected;
            runSearch(1);
        });

        pagination.addEventListener('click', function (event) {
            const button = event.target.closest('[data-dep3-page]');
            if (!button) return;
            runSearch(parseInt(button.getAttribute('data-dep3-page'), 10) || 1);
        });

        const params = new URLSearchParams(window.location.search);
        const initial = (params.get('dep_q') || '').trim();
        const initialCategory = (params.get('dep_cat') || '').trim();
        if (initial) {
            input.value = initial;
            currentQuery = initial;
            currentCategory = initialCategory;
            runSearch(1, false);
        }

        async function runSearch(page, updateUrl = true) {
            if (!currentQuery) return;
            if (controller) controller.abort();
            controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), 40000);

            status.className = 'dependiente-v3__status is-loading';
            status.textContent = currentCategory ? 'Afinando con tu elección…' : 'Interpretando lo que necesitas…';
            workspace.hidden = false;
            productsSection.hidden = true;
            categoriesSection.hidden = true;

            const url = new URL(config.endpoint, window.location.origin);
            url.searchParams.set('q', currentQuery);
            url.searchParams.set('page', String(page || 1));
            if (currentCategory) url.searchParams.set('category', currentCategory);

            try {
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                });
                const text = await response.text();
                let data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!response.ok || !data) {
                    const message = data && data.message ? data.message : ('Respuesta inválida del servidor (' + response.status + ').');
                    throw new Error(message);
                }

                render(data);
                if (updateUrl) updateBrowserUrl();
            } catch (error) {
                const aborted = error && error.name === 'AbortError';
                status.className = 'dependiente-v3__status is-error';
                status.innerHTML = '<strong>' + (aborted ? 'La búsqueda ha tardado demasiado.' : 'No se pudo completar la búsqueda.') + '</strong>' +
                    '<span>' + esc(aborted ? 'El nuevo flujo canceló la petición tras 40 segundos para no bloquear la página.' : (error.message || 'Error de conexión.')) + '</span>';
            } finally {
                window.clearTimeout(timer);
            }
        }

        function updateBrowserUrl() {
            const browserUrl = new URL(window.location.href);
            browserUrl.searchParams.set('dep_q', currentQuery);
            if (currentCategory) browserUrl.searchParams.set('dep_cat', currentCategory);
            else browserUrl.searchParams.delete('dep_cat');
            window.history.replaceState({}, '', browserUrl.toString());
        }

        function render(data) {
            const decision = data.decision || {};
            const suggestions = data.suggestions || data.categories || [];
            renderStatus(data, decision, suggestions);
            renderSuggestions(suggestions, decision);

            if (decision.show_products) {
                renderProducts(data.products || []);
                renderPagination(data.pagination || {});
            } else {
                productsSection.hidden = true;
                products.innerHTML = '';
                pagination.innerHTML = '';
            }
            renderDebug(data);
        }

        function renderStatus(data, decision, suggestions) {
            const candidateCount = Number(decision.candidate_count || (data.pagination && data.pagination.candidate_total) || 0);
            const selected = suggestions.find(item => item.selected || (currentCategory && currentCategory === item.slug));
            status.className = 'dependiente-v3__status ' + (decision.show_products ? 'is-ok' : 'is-waiting');

            if (decision.reason === 'client_choice') {
                status.innerHTML = '<strong>Entendido' + (selected ? ': ' + esc(selected.name) : '') + '.</strong>' +
                    '<span> He usado tu elección para ordenar ' + esc(candidateCount) + ' coincidencias.</span>';
                return;
            }
            if (decision.show_products) {
                status.innerHTML = '<strong>Creo que sé por dónde vas.</strong><span> Te enseño las opciones que he entendido y, debajo, los productos que mejor encajan.</span>';
                return;
            }
            if (suggestions.length) {
                status.innerHTML = '<strong>Necesito una pista más.</strong><span> Elige una de las opciones de abajo antes de que te recomiende productos.</span>';
                return;
            }
            status.innerHTML = '<strong>No tengo suficiente claridad todavía.</strong><span> Prueba a explicar qué quieres hacer, sobre qué objeto y para qué uso.</span>';
        }

        function renderSuggestions(items, decision) {
            if (!items.length) {
                categoriesSection.hidden = true;
                categories.innerHTML = '';
                if (guidanceCopy) guidanceCopy.textContent = '';
                return;
            }
            categoriesSection.hidden = false;
            if (guidanceCopy) guidanceCopy.textContent = decision.message || 'Elige la opción que más se parezca a lo que necesitas.';
            categories.innerHTML = items.slice(0, 8).map(function (item, index) {
                const active = !!(item.selected || (currentCategory && currentCategory === item.slug));
                const rank = Number(item.rank || (index + 1));
                return '<button type="button" class="dependiente-v3__category dependiente-v3__choice' + (active ? ' is-active' : '') + '" ' +
                    'data-dep3-category="' + esc(item.slug) + '" aria-pressed="' + (active ? 'true' : 'false') + '">' +
                    '<span class="dependiente-v3__choice-kicker">Opción ' + String(rank).padStart(2, '0') + '</span>' +
                    '<strong class="dependiente-v3__choice-name">' + esc(item.name) + '</strong>' +
                    '<span class="dependiente-v3__choice-meta">' + (active ? 'Elegida' : 'Elegir') + '</span>' +
                    '</button>';
            }).join('');
        }

        function renderProducts(items) {
            productsSection.hidden = false;
            clearCategory.hidden = !currentCategory;
            if (!items.length) {
                products.innerHTML = '<div class="dependiente-v3__empty"><strong>No hay productos en esta opción.</strong><span>Quita la elección o prueba otra de las interpretaciones.</span></div>';
                return;
            }
            products.innerHTML = items.map(function (item) {
                const cats = (item.categories || []).slice(0, 2).join(' · ');
                const image = item.image ? '<img src="' + esc(item.image) + '" alt="" loading="lazy">' : '<span class="dependiente-v3__image-placeholder">Producto</span>';
                const price = item.price_html || (item.price != null ? esc(item.price) : '');
                return '<article class="dependiente-v3__product">' +
                    '<a class="dependiente-v3__image" href="' + esc(item.url) + '">' + image + '</a>' +
                    '<div class="dependiente-v3__product-body">' +
                    '<div class="dependiente-v3__meta">' + esc(cats) + '</div>' +
                    '<h3><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h3>' +
                    (item.excerpt ? '<p>' + esc(item.excerpt) + '</p>' : '') +
                    '<div class="dependiente-v3__product-foot"><span class="dependiente-v3__price">' + price + '</span>' +
                    '<span class="dependiente-v3__stock ' + (item.in_stock ? 'is-in' : 'is-out') + '">' + (item.in_stock ? 'En stock' : 'Sin stock') + '</span></div>' +
                    '<a class="dependiente-v3__product-link" href="' + esc(item.url) + '">Ver producto</a>' +
                    '</div></article>';
            }).join('');
        }

        function renderPagination(p) {
            const pages = Number(p.pages || 1);
            const page = Number(p.page || 1);
            if (pages <= 1) {
                pagination.innerHTML = '';
                return;
            }
            let html = '';
            if (page > 1) html += '<button type="button" data-dep3-page="' + (page - 1) + '">← Anterior</button>';
            html += '<span>Página ' + page + ' de ' + pages + '</span>';
            if (page < pages) html += '<button type="button" data-dep3-page="' + (page + 1) + '">Siguiente →</button>';
            pagination.innerHTML = html;
        }

        function renderDebug(data) {
            if (!config.showDebug || !debugContent) return;
            const i = data.interpretation || {};
            const d = data.debug || {};
            const decision = data.decision || d.decision || {};
            const groups = (i.groups || []).map(g => '<li><strong>' + esc(g.canonical) + '</strong> <small>' + esc(g.role) + ' · ' + esc((g.variants || []).join(' / ')) + '</small></li>').join('');
            const routes = (i.routes || []).map(r => {
                const condition = [r.source_group && r.source_slug ? (r.source_group + '=' + r.source_slug) : '', r.context_group && r.context_slug ? (r.context_group + '=' + r.context_slug) : ''].filter(Boolean).join(' + ');
                return '<li>' + esc(r.result_role || r.target_group || 'ruta') + ' → <strong>' + esc(r.target_slug || ('vocab #' + r.target_vocabulary_id)) + '</strong>' +
                    '<small>' + esc(condition || 'sin condición') + ' · peso ' + esc(r.weight) + (r.target_vocabulary_id ? ' · vocab #' + esc(r.target_vocabulary_id) : '') + '</small></li>';
            }).join('');
            const ranked = (d.ranked_top || []).map(function (r, idx) {
                const evidence = (r.evidence || []).map(e => esc(e.concept) + ' → ' + esc(e.field) + ' +' + esc(e.points)).join(' · ');
                return '<li><strong>#' + (idx + 1) + ' · ' + esc(r.title) + '</strong>' +
                    '<small>score ' + esc(r.score) + ' · cobertura ' + esc(r.coverage) + '/' + esc(r.coverage_total) + (r.solution_priority ? ' · RUTA' : '') + '</small>' +
                    '<em>' + evidence + '</em></li>';
            }).join('');
            const retrieval = d.retrieval || {};
            const suggestionLog = (data.suggestions || data.categories || []).map(function (c, idx) {
                return '<li><strong>#' + (idx + 1) + ' · ' + esc(c.name) + '</strong><small>primer producto #' + (Number(c.first_rank || 0) + 1) + ' · score máx. ' + esc(c.best_score || 0) + ' · ' + esc(c.count || 0) + ' coincidencias</small></li>';
            }).join('');
            debugContent.innerHTML =
                '<section><h3>INTÉRPRETE</h3>' +
                '<p><b>Cliente:</b> ' + esc(i.raw || data.query || '') + '</p>' +
                '<p><b>Normalizado:</b> ' + esc(i.normalized || '') + '</p>' +
                '<p><b>Filtrado:</b> ' + esc(i.filtered || '') + '</p>' +
                '<p><b>Acciones:</b> ' + esc((i.actions || []).join(' · ') || '—') + '</p>' +
                '<p><b>Ruido:</b> ' + esc((i.ignored || []).join(' · ') || '—') + '</p>' +
                '<h4>Conceptos entregados</h4><ul>' + (groups || '<li>—</li>') + '</ul>' +
                '<h4>Rutas aprendidas</h4><ul>' + (routes || '<li>—</li>') + '</ul></section>' +
                '<section><h3>DECISIÓN VISUAL</h3>' +
                '<p><b>Confianza:</b> ' + esc(decision.confidence || 0) + '% · <b>Nivel:</b> ' + esc(decision.level || '—') + '</p>' +
                '<p><b>Mostrar productos:</b> ' + (decision.show_products ? 'sí' : 'no') + ' · <b>Motivo:</b> ' + esc(decision.reason || '—') + '</p>' +
                '<p><b>Candidatos:</b> ' + esc(decision.candidate_count || 0) + '</p></section>' +
                '<section><h3>DEPENDIENTE</h3>' +
                '<p><b>Exactos:</b> ' + esc(retrieval.exact || 0) + ' · <b>Todos los conceptos:</b> ' + esc(retrieval.conjunctive || 0) + '</p>' +
                '<p><b>Parciales:</b> ' + esc(retrieval.partial || 0) + ' · <b>Vocabulary:</b> ' + esc(retrieval.vocabulary || 0) + ' · <b>Academia L9:</b> ' + esc(retrieval.lesson9 || 0) + '</p>' +
                '<p><b>Rutas solución:</b> ' + esc(retrieval.routes || 0) + ' · <b>Rutas contexto:</b> ' + esc(retrieval.context_routes || 0) + ' · <b>Catálogo vivo:</b> ' + esc(retrieval.live_fallback || 0) + '</p>' +
                '<p><b>Antes comprobación técnica:</b> ' + esc(d.candidates_before_publish_check || 0) + '</p>' +
                '<p><b>Después:</b> ' + esc(d.candidates_after_publish_check || 0) + ' · <b>No publicados:</b> ' + esc(d.discarded_unpublished || 0) + '</p>' +
                '<p><b>Tiempo:</b> ' + esc(d.request_ms || d.elapsed_ms || 0) + ' ms</p></section>' +
                '<section><h3>8 INTERPRETACIONES VISUALES</h3><ol class="dependiente-v3__rank-log">' + (suggestionLog || '<li>Sin opciones.</li>') + '</ol></section>' +
                '<section><h3>RANKING TRANSPARENTE</h3><ol class="dependiente-v3__rank-log">' + (ranked || '<li>Sin resultados puntuados.</li>') + '</ol></section>';
        }
    }
})();
