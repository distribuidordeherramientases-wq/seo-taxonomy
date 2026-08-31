(function () {
    'use strict';

    const config = window.SEODependienteConfig || {};
    const roots = document.querySelectorAll('[data-dependiente-root]');

    roots.forEach(initDependiente);

    function initDependiente(root) {
        const elements = {
            form: root.querySelector('[data-dependiente-search-form]'),
            query: root.querySelector('[data-dependiente-query]'),
            submit: root.querySelector('[data-dependiente-search-form] button[type="submit"]'),
            examples: root.querySelector('[data-dependiente-examples]'),
            discovery: root.querySelector('[data-dependiente-discovery]'),
            actions: root.querySelector('[data-dependiente-actions]'),
            tools: root.querySelector('[data-dependiente-tools]'),
            paths: root.querySelectorAll('[data-dependiente-mode]'),
            workspace: root.querySelector('[data-dependiente-workspace]'),
            filters: root.querySelector('[data-dependiente-filters]'),
            related: root.querySelector('[data-dependiente-related]'),
            filterToggle: root.querySelector('[data-dependiente-filter-toggle]'),
            summary: root.querySelector('[data-dependiente-summary]'),
            sort: root.querySelector('[data-dependiente-sort]'),
            activeFilters: root.querySelector('[data-dependiente-active-filters]'),
            status: root.querySelector('[data-dependiente-status]'),
            results: root.querySelector('[data-dependiente-results]'),
            pagination: root.querySelector('[data-dependiente-pagination]'),
            compareTray: root.querySelector('[data-dependiente-compare-tray]'),
            compareCount: root.querySelector('[data-dependiente-compare-count]'),
            compareClear: root.querySelector('[data-dependiente-compare-clear]'),
            compareOpen: root.querySelector('[data-dependiente-compare-open]'),
            dialog: root.querySelector('[data-dependiente-dialog]'),
            dialogClose: root.querySelector('[data-dependiente-dialog-close]'),
            compareContent: root.querySelector('[data-dependiente-compare-content]')
        };

        const state = {
            q: '',
            contextLabel: '',
            mode: 'need',
            modeSource: 'default',
            page: 1,
            perPage: Number(config.resultsPerPage || 18),
            orderby: 'relevance',
            filters: emptyFilters(),
            facets: null,
            bootstrap: null,
            loading: false,
            searchId: '',
            semanticHint: null,
            clarification: null,
            clarificationTimer: null,
            hasResultInteraction: false,
            compare: loadCompareIds()
        };

        bindEvents();
        renderLoadingMenus();
        loadBootstrap();
        renderCompareTray();

        const initialQuery = new URLSearchParams(window.location.search).get('dep_q');
        if (initialQuery) {
            elements.query.value = initialQuery;
            state.q = initialQuery;
            search(true);
        }

        function bindEvents() {
            // Las imagenes de proveedor pueden vivir fuera de WordPress. Si el
            // hosting remoto rechaza una URL concreta, evitamos el icono roto y
            // mostramos el logo corporativo configurado como fallback.
            root.addEventListener('error', function (event) {
                const image = event.target;
                if (!image || image.tagName !== 'IMG') {
                    return;
                }
                const fallback = String(config.placeholderImage || '');
                if (fallback && image.dataset.seoFallbackApplied !== '1') {
                    image.dataset.seoFallbackApplied = '1';
                    image.src = fallback;
                    return;
                }
                image.style.visibility = 'hidden';
            }, true);

            elements.form.addEventListener('submit', function (event) {
                event.preventDefault();
                const nextQuery = elements.query.value.trim();

                // Una busqueda escrita despues de entrar por una tarjeta visual
                // inicia una necesidad nueva. Evita arrastrar, por ejemplo, el
                // filtro/plataforma Milwaukee y mode=tool a "se me ha roto un grifo".
                if (nextQuery && state.modeSource === 'menu') {
                    state.filters = emptyFilters();
                    state.orderby = 'relevance';
                    if (elements.sort) elements.sort.value = 'relevance';
                    setMode('need', 'default');
                }

                state.q = nextQuery;
                state.contextLabel = '';
                state.semanticHint = null;
                state.page = 1;
                search(true);
            });

            elements.paths.forEach(function (button) {
                button.addEventListener('click', function () {
                    setMode(button.dataset.dependienteMode || 'need', 'path');
                    if (elements.query) {
                        elements.query.focus({ preventScroll: true });
                    }
                });
            });

            elements.sort.addEventListener('change', function () {
                state.orderby = elements.sort.value;
                state.page = 1;
                search(false);
            });

            elements.filterToggle.addEventListener('click', function () {
                const open = elements.filters.classList.toggle('is-open');
                elements.filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            elements.filters.addEventListener('submit', function (event) {
                const form = event.target.closest('[data-dependiente-filter-form]');
                if (!form) return;
                event.preventDefault();
                readFiltersFromForm(form);
                state.modeSource = 'filter';
                state.page = 1;
                search(false);
                if (window.innerWidth <= 860) {
                    elements.filters.classList.remove('is-open');
                    elements.filterToggle.setAttribute('aria-expanded', 'false');
                }
            });

            elements.filters.addEventListener('click', function (event) {
                const reset = event.target.closest('[data-dependiente-filter-reset]');
                if (!reset) return;
                event.preventDefault();
                state.filters = emptyFilters();
                state.contextLabel = '';
                state.page = 1;
                search(false);
            });

            elements.activeFilters.addEventListener('click', function (event) {
                const chip = event.target.closest('[data-filter-remove]');
                if (!chip) return;
                removeFilter(chip.dataset.filterRemove, chip.dataset.filterGroup || '', chip.dataset.filterValue || '');
                state.page = 1;
                search(false);
            });

            elements.pagination.addEventListener('click', function (event) {
                const button = event.target.closest('[data-page]');
                if (!button) return;
                state.page = Number(button.dataset.page || 1);
                search(false);
                elements.workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            elements.results.addEventListener('click', function (event) {
                const reset = event.target.closest('[data-dependiente-zero-reset]');
                if (reset) {
                    event.preventDefault();
                    state.filters = emptyFilters();
                    state.contextLabel = '';
                    state.page = 1;
                    search(false);
                    return;
                }

                const alternative = event.target.closest('[data-dependiente-zero-filter]');
                if (alternative) {
                    event.preventDefault();
                    let filter = {};
                    try { filter = JSON.parse(alternative.dataset.dependienteZeroFilter || '{}'); } catch (error) { filter = {}; }
                    state.filters = emptyFilters();
                    applyCardFilter(filter);
                    state.q = '';
                    state.contextLabel = alternative.dataset.dependienteZeroLabel || '';
                    elements.query.value = '';
                    state.page = 1;
                    setMode(alternative.dataset.dependienteZeroMode || 'need', 'menu');
                    search(true);
                    return;
                }

                const clarify = event.target.closest('[data-dependiente-clarify]');
                if (clarify) {
                    event.preventDefault();
                    handleClarificationChoice(clarify);
                    return;
                }

                const other = event.target.closest('[data-dependiente-clarify-other]');
                if (other) {
                    event.preventDefault();
                    showClarificationOther(other);
                    return;
                }

                const productLink = event.target.closest('[data-dependiente-product-link]');
                if (productLink) {
                    state.hasResultInteraction = true;
                    clearClarificationTimer();
                    removeClarification();
                    trackResultClick(
                        Number(productLink.dataset.productId || 0),
                        Number(productLink.dataset.position || 0)
                    );
                    return;
                }

                const button = event.target.closest('[data-compare-id]');
                if (!button) return;
                event.preventDefault();
                toggleCompare(Number(button.dataset.compareId || 0));
            });

            elements.results.addEventListener('submit', function (event) {
                const form = event.target.closest('[data-dependiente-clarify-other-form]');
                if (!form) return;
                event.preventDefault();
                const input = form.querySelector('[data-dependiente-clarify-other-input]');
                const value = input ? input.value.trim() : '';
                if (!value) {
                    if (input) input.focus();
                    return;
                }
                handleClarificationOther(form, value);
            });

            elements.compareClear.addEventListener('click', function () {
                state.compare.clear();
                persistCompareIds(state.compare);
                renderCompareTray();
                syncCompareButtons();
            });

            elements.compareOpen.addEventListener('click', openComparison);
            elements.dialogClose.addEventListener('click', closeDialog);
            elements.dialog.addEventListener('click', function (event) {
                if (event.target === elements.dialog) closeDialog();
            });
        }

        async function loadBootstrap() {
            try {
                const data = await api('bootstrap', { method: 'GET' });
                state.bootstrap = data;
                renderExamples(data.examples || []);
                renderVisualMenu(elements.actions, data.actions || [], 'need');
                renderVisualMenu(elements.tools, data.tools || [], 'tool');
            } catch (error) {
                renderMenuError(elements.actions);
                renderMenuError(elements.tools);
            }
        }

        function renderLoadingMenus() {
            [elements.actions, elements.tools].forEach(function (container) {
                container.innerHTML = Array.from({ length: 4 }).map(function () {
                    return '<div class="seo-dependiente__visual-card seo-dependiente__skeleton" aria-hidden="true"></div>';
                }).join('');
            });
        }

        function renderMenuError(container) {
            container.innerHTML = '<div class="seo-dependiente__empty"><strong>Menú en preparación</strong><span>La búsqueda escrita sigue disponible.</span></div>';
        }

        function renderExamples(examples) {
            elements.examples.innerHTML = examples.map(function (example) {
                return '<button type="button" data-example="' + escapeAttr(example) + '">' + escapeHtml(example) + '</button>';
            }).join('');
            elements.examples.querySelectorAll('[data-example]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const value = button.dataset.example || '';
                    elements.query.value = value;
                    state.q = value;
                    state.contextLabel = '';
                    state.semanticHint = null;
                    state.page = 1;
                    search(true);
                });
            });
        }

        function renderVisualMenu(container, cards, mode) {
            if (!cards.length) {
                renderMenuError(container);
                return;
            }
            container.innerHTML = cards.map(function (card) {
                const imageClass = card.image_kind === 'logo' ? ' seo-dependiente__visual-card--logo' : '';
                return '<button type="button" class="seo-dependiente__visual-card' + imageClass + '" data-card-filter="' + escapeAttr(JSON.stringify(card.filter || {})) + '">' +
                    '<img src="' + escapeAttr(card.image || config.placeholderImage || '') + '" alt="" loading="lazy" decoding="async">' +
                    '<span class="seo-dependiente__visual-card-arrow" aria-hidden="true">→</span>' +
                    '<span class="seo-dependiente__visual-card-content"><strong>' + escapeHtml(card.label) + '</strong><small>' + numberFormat(card.count) + ' opciones</small></span>' +
                    '</button>';
            }).join('');

            container.querySelectorAll('[data-card-filter]').forEach(function (button, index) {
                button.addEventListener('click', function () {
                    let filter = {};
                    try { filter = JSON.parse(button.dataset.cardFilter || '{}'); } catch (error) { filter = {}; }
                    state.filters = emptyFilters();
                    applyCardFilter(filter);
                    state.contextLabel = cards[index] ? cards[index].label : '';
                    state.q = '';
                    state.semanticHint = null;
                    elements.query.value = '';
                    state.page = 1;
                    setMode(mode, 'menu');
                    search(true);
                });
            });
        }

        function applyCardFilter(filter) {
            const slug = filter.slug || '';
            if (!slug) return;
            if (filter.type === 'categories') state.filters.categories = [slug];
            if (filter.type === 'tags') state.filters.tags = [slug];
            if (filter.type === 'vocabulary' && filter.group) state.filters.vocabulary[filter.group] = [slug];
            if (filter.type === 'attributes' && filter.group) state.filters.attributes[filter.group] = [slug];
        }

        function setMode(mode, source) {
            state.mode = mode;
            if (source) state.modeSource = source;
            elements.paths.forEach(function (button) {
                button.classList.toggle('is-active', button.dataset.dependienteMode === mode);
            });

            const placeholders = config.modePlaceholders || {};
            const buttons = config.modeButtons || {};
            elements.query.placeholder = placeholders[mode] || placeholders.need || 'Cuéntame qué necesitas';
            if (elements.submit) {
                elements.submit.textContent = buttons[mode] || buttons.need || 'Buscar';
            }
        }

        async function search(resetScroll) {
            if (state.loading) return;
            clearClarificationTimer();
            removeClarification();
            state.hasResultInteraction = false;
            state.loading = true;
            elements.workspace.hidden = false;
            if (elements.related) {
                elements.related.hidden = true;
                elements.related.innerHTML = '';
            }
            elements.status.textContent = config.labels && config.labels.loading ? config.labels.loading : 'Revisando el catálogo…';
            elements.results.innerHTML = Array.from({ length: 6 }).map(function () {
                return '<div class="seo-dependiente__skeleton" aria-hidden="true"></div>';
            }).join('');
            elements.pagination.innerHTML = '';
            if (resetScroll) {
                elements.workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            try {
                const data = await api('search', {
                    method: 'POST',
                    body: {
                        q: state.q,
                        mode: state.mode,
                        page: state.page,
                        per_page: state.perPage,
                        orderby: state.orderby,
                        filters: state.filters,
                        semantic_hint: state.semanticHint || null
                    }
                });
                state.facets = data.facets || null;
                state.searchId = String(data.search_id || '');
                renderSummary(data);
                renderFilters(data.facets || {});
                renderActiveFilters();
                renderResults(data.results || [], data);
                renderRelated(data.related || [], Number(data.total || 0));
                renderPagination(data.page || 1, data.pages || 0);
                state.clarification = data.clarification || null;
                scheduleClarification(state.clarification);
                elements.status.textContent = data.truncated ? 'He encontrado muchas coincidencias. Añade una medida, marca, compatibilidad o uso para afinar mejor.' : '';
                updateUrl();
            } catch (error) {
                elements.summary.innerHTML = '<strong>No he podido terminar la búsqueda.</strong>';
                elements.status.textContent = error.message || (config.labels && config.labels.error) || 'Ha ocurrido un error.';
                elements.results.innerHTML = '<div class="seo-dependiente__empty"><strong>Prueba de nuevo</strong><span>Comprueba la conexión o simplifica la consulta.</span></div>';
                if (elements.related) {
                    elements.related.hidden = true;
                    elements.related.innerHTML = '';
                }
            } finally {
                state.loading = false;
                syncCompareButtons();
            }
        }

        function clearClarificationTimer() {
            if (state.clarificationTimer) {
                window.clearTimeout(state.clarificationTimer);
                state.clarificationTimer = null;
            }
        }

        function removeClarification() {
            const node = elements.results.querySelector('[data-dependiente-clarification]');
            if (node) node.remove();
        }

        function scheduleClarification(clarification) {
            clearClarificationTimer();
            if (!clarification || !clarification.should_ask || !Array.isArray(clarification.options) || clarification.options.length < 2) {
                return;
            }
            const show = function () {
                state.clarificationTimer = null;
                if (state.hasResultInteraction || !state.searchId) return;
                renderClarification(clarification);
            };
            const delay = Math.max(0, Number(clarification.delay_ms || 0));
            if (delay === 0) show();
            else state.clarificationTimer = window.setTimeout(show, delay);
        }

        function renderClarification(clarification) {
            if (!state.searchId || elements.results.querySelector('[data-dependiente-clarification]')) return;
            const options = (clarification.options || []).map(function (option) {
                return '<button type="button" class="seo-dependiente__empty-action" data-dependiente-clarify' +
                    ' data-role="' + escapeAttr(option.role || clarification.role || 'term') + '"' +
                    ' data-value="' + escapeAttr(option.value || '') + '"' +
                    ' data-label="' + escapeAttr(option.label || option.value || '') + '"' +
                    ' data-source="' + escapeAttr(option.source || 'closed_option') + '"' +
                    ' data-source-group="' + escapeAttr(option.source_group || '') + '"' +
                    ' data-source-slug="' + escapeAttr(option.source_slug || '') + '"' +
                    ' data-filter="' + escapeAttr(JSON.stringify(option.filter || {})) + '">' +
                    escapeHtml(option.label || option.value || '') + '</button>';
            }).join('');

            const html = '<section class="seo-dependiente__empty-actions seo-dependiente__clarification" data-dependiente-clarification>' +
                '<strong>' + escapeHtml(clarification.question || '¿Puedes concretar un poco más?') + '</strong>' +
                '<div>' + options + '<button type="button" class="seo-dependiente__empty-action" data-dependiente-clarify-other>Otro</button></div>' +
                '<div data-dependiente-clarify-other-slot></div>' +
                '</section>';
            elements.results.insertAdjacentHTML('afterbegin', html);
            trackClarificationShown(clarification);
        }

        function showClarificationOther(button) {
            const box = button.closest('[data-dependiente-clarification]');
            if (!box) return;
            const slot = box.querySelector('[data-dependiente-clarify-other-slot]');
            if (!slot) return;
            slot.innerHTML = '<form data-dependiente-clarify-other-form class="seo-dependiente__filter-form">' +
                '<label>Cuéntame en pocas palabras<input type="text" maxlength="120" data-dependiente-clarify-other-input placeholder="Por ejemplo: reparar una fuga"></label>' +
                '<button type="submit" class="seo-dependiente__filter-apply">Confirmar</button></form>';
            const input = slot.querySelector('[data-dependiente-clarify-other-input]');
            if (input) input.focus();
        }

        function handleClarificationChoice(button) {
            const option = {
                role: button.dataset.role || (state.clarification && state.clarification.role) || 'term',
                value: button.dataset.value || '',
                label: button.dataset.label || button.textContent || '',
                source: button.dataset.source || 'closed_option',
                source_group: button.dataset.sourceGroup || '',
                source_slug: button.dataset.sourceSlug || ''
            };
            if (!option.value) return;
            let filter = {};
            try { filter = JSON.parse(button.dataset.filter || '{}'); } catch (error) { filter = {}; }
            confirmClarification(option, filter, false);
        }

        function handleClarificationOther(form, value) {
            const role = (state.clarification && state.clarification.role) || 'term';
            confirmClarification({
                role: role,
                value: value,
                label: value,
                source: 'other_text',
                source_group: '',
                source_slug: ''
            }, {}, true);
        }

        function confirmClarification(option, filter, isOther) {
            const originalSearchId = state.searchId;
            if (!originalSearchId) return;
            state.hasResultInteraction = true;
            clearClarificationTimer();
            sendClarificationFeedback(originalSearchId, option, Boolean(isOther));

            // Las opciones basadas en el vocabulario real pueden convertirse
            // directamente en un filtro del catalogo. La intencion confirmada se
            // envia como pista semantica sin modificar el texto que escribio el cliente.
            if (filter && filter.slug) {
                applyCardFilter(filter);
            }
            state.semanticHint = {
                role: option.role,
                value: option.value,
                label: option.label,
                source: option.source,
                source_group: option.source_group || '',
                source_slug: option.source_slug || ''
            };
            state.page = 1;
            removeClarification();
            search(false);
        }

        function trackClarificationShown(clarification) {
            if (!state.searchId) return;
            sendFeedbackEvent({
                search_id: state.searchId,
                event: 'clarification_shown',
                question: clarification.question || '',
                options: clarification.options || []
            });
        }

        function sendClarificationFeedback(searchId, option, isOther) {
            sendFeedbackEvent({
                search_id: searchId,
                event: 'clarify',
                role: option.role || 'term',
                choice_value: option.value || '',
                label: option.label || option.value || '',
                source: option.source || 'closed_option',
                source_group: option.source_group || '',
                source_slug: option.source_slug || '',
                is_other: isOther ? 1 : 0
            });
        }

        function sendFeedbackEvent(body) {
            const url = String(config.root || '') + 'search-feedback';
            try {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body || {}),
                    keepalive: true
                }).catch(function () { /* la telemetria nunca bloquea la experiencia */ });
            } catch (error) {
                // El aprendizaje es auxiliar y nunca debe romper la busqueda.
            }
        }

        function trackResultClick(productId, position) {
            if (!state.searchId || !productId) return;
            sendFeedbackEvent({
                search_id: state.searchId,
                event: 'click',
                product_id: Number(productId),
                position: Number(position || 0)
            });
        }

        function renderSummary(data) {
            const total = Number(data.total || 0);
            let subject = state.q ? 'para “' + escapeHtml(state.q) + '”' : (state.contextLabel ? 'para ' + escapeHtml(state.contextLabel) : 'con los criterios elegidos');
            if (!total) {
                elements.summary.innerHTML = '<span><strong>No encuentro una coincidencia clara</strong> ' + subject + '.</span>';
                return;
            }
            const noun = total === 1 ? 'opción' : 'opciones';
            elements.summary.innerHTML = '<span><strong>He encontrado ' + numberFormat(total) + ' ' + noun + '</strong> ' + subject + '. Te muestro primero las que mejor encajan.</span>';
        }

        function renderFilters(facets) {
            const groups = [];
            groups.push(renderFacetGroup('Categoría', 'categories', '', facets.categories || [], true));
            groups.push(renderFacetGroup('Marca', 'brands', '', facets.brands || [], false));

            const vocabulary = facets.vocabulary || {};
            groups.push(renderFacetGroup('Qué quieres hacer', 'vocabulary', 'aplicacion', vocabulary.aplicacion || [], true));
            groups.push(renderFacetGroup('Herramienta o plataforma', 'vocabulary', 'plataforma', vocabulary.plataforma || [], true));
            groups.push(renderFacetGroup('Tipo de producto', 'vocabulary', 'tipo', vocabulary.tipo || [], false));
            groups.push(renderFacetGroup('Subtipo', 'vocabulary', 'subtipo', vocabulary.subtipo || [], false));
            groups.push(renderFacetGroup('Etiquetas', 'tags', '', facets.tags || [], false));

            (facets.attributes || []).forEach(function (attribute, index) {
                groups.push(renderFacetGroup(attribute.label, 'attributes', attribute.key, attribute.values || [], index < 3));
            });

            groups.push(renderRangeGroup('Precio', 'price', facets.ranges && facets.ranges.price, config.currencySymbol || '€'));
            groups.push(renderRangeGroup('Peso', 'weight', facets.ranges && facets.ranges.weight, config.weightUnit || 'kg'));
            groups.push(renderRangeGroup('Longitud', 'length', facets.ranges && facets.ranges.length, config.dimensionUnit || 'cm'));
            groups.push(renderRangeGroup('Anchura', 'width', facets.ranges && facets.ranges.width, config.dimensionUnit || 'cm'));
            groups.push(renderRangeGroup('Altura', 'height', facets.ranges && facets.ranges.height, config.dimensionUnit || 'cm'));
            groups.push(renderFacetGroup('Disponibilidad', 'stock', '', facets.stock || [], true));

            elements.filters.innerHTML = '<form class="seo-dependiente__filter-form" data-dependiente-filter-form>' +
                '<div class="seo-dependiente__filter-head"><h2>Afina la búsqueda</h2><button type="button" class="seo-dependiente__filter-reset" data-dependiente-filter-reset>Limpiar</button></div>' +
                groups.filter(Boolean).join('') +
                '<button type="submit" class="seo-dependiente__filter-apply">Aplicar filtros</button>' +
                '</form>';
        }

        function renderFacetGroup(label, kind, group, items, open) {
            if (!items || !items.length) return '';
            const selected = selectedValues(kind, group);
            const options = items.slice(0, 60).map(function (item) {
                const checked = selected.includes(item.slug) ? ' checked' : '';
                return '<label class="seo-dependiente__filter-option">' +
                    '<input type="checkbox" data-filter-kind="' + escapeAttr(kind) + '" data-filter-group="' + escapeAttr(group) + '" value="' + escapeAttr(item.slug) + '"' + checked + '>' +
                    '<span>' + escapeHtml(item.label) + '</span><small>' + numberFormat(item.count) + '</small></label>';
            }).join('');
            return '<details class="seo-dependiente__filter-group"' + (open ? ' open' : '') + '><summary>' + escapeHtml(label) + '</summary><div class="seo-dependiente__filter-options">' + options + '</div></details>';
        }

        function renderRangeGroup(label, field, range, unit) {
            if (!range || range.min === null || range.max === null) return '';
            const selected = state.filters.ranges[field] || {};
            return '<details class="seo-dependiente__filter-group"><summary>' + escapeHtml(label) + '</summary>' +
                '<div class="seo-dependiente__ranges">' +
                '<label>Mínimo<input type="number" step="any" data-range-field="' + escapeAttr(field) + '" data-range-bound="min" value="' + escapeAttr(selected.min !== undefined && selected.min !== null ? selected.min : '') + '" placeholder="' + escapeAttr(range.min) + '"></label>' +
                '<label>Máximo<input type="number" step="any" data-range-field="' + escapeAttr(field) + '" data-range-bound="max" value="' + escapeAttr(selected.max !== undefined && selected.max !== null ? selected.max : '') + '" placeholder="' + escapeAttr(range.max) + '"></label>' +
                '</div><small class="seo-dependiente__range-meta">Rango disponible: ' + escapeHtml(String(range.min)) + '–' + escapeHtml(String(range.max)) + ' ' + escapeHtml(unit) + '</small></details>';
        }

        function selectedValues(kind, group) {
            if (kind === 'categories') return state.filters.categories || [];
            if (kind === 'tags') return state.filters.tags || [];
            if (kind === 'brands') return state.filters.brands || [];
            if (kind === 'stock') return state.filters.stock || [];
            if (kind === 'vocabulary') return state.filters.vocabulary[group] || [];
            if (kind === 'attributes') return state.filters.attributes[group] || [];
            return [];
        }

        function readFiltersFromForm(form) {
            const next = emptyFilters();
            form.querySelectorAll('[data-filter-kind]:checked').forEach(function (input) {
                const kind = input.dataset.filterKind;
                const group = input.dataset.filterGroup || '';
                const value = input.value;
                if (kind === 'categories') next.categories.push(value);
                if (kind === 'tags') next.tags.push(value);
                if (kind === 'brands') next.brands.push(value);
                if (kind === 'stock') next.stock.push(value);
                if (kind === 'vocabulary') {
                    next.vocabulary[group] = next.vocabulary[group] || [];
                    next.vocabulary[group].push(value);
                }
                if (kind === 'attributes') {
                    next.attributes[group] = next.attributes[group] || [];
                    next.attributes[group].push(value);
                }
            });
            form.querySelectorAll('[data-range-field]').forEach(function (input) {
                if (input.value === '') return;
                const field = input.dataset.rangeField;
                const bound = input.dataset.rangeBound;
                next.ranges[field] = next.ranges[field] || {};
                next.ranges[field][bound] = Number(input.value);
            });
            state.filters = next;
        }

        function renderActiveFilters() {
            if (!state.facets) {
                elements.activeFilters.innerHTML = '';
                return;
            }
            const chips = [];
            addFacetChips(chips, 'categories', '', state.filters.categories, state.facets.categories || []);
            addFacetChips(chips, 'tags', '', state.filters.tags, state.facets.tags || []);
            addFacetChips(chips, 'brands', '', state.filters.brands, state.facets.brands || []);
            addFacetChips(chips, 'stock', '', state.filters.stock, state.facets.stock || []);

            Object.keys(state.filters.vocabulary || {}).forEach(function (group) {
                const items = state.facets.vocabulary && state.facets.vocabulary[group] ? state.facets.vocabulary[group] : [];
                addFacetChips(chips, 'vocabulary', group, state.filters.vocabulary[group], items);
            });
            Object.keys(state.filters.attributes || {}).forEach(function (group) {
                const attr = (state.facets.attributes || []).find(function (item) { return item.key === group; });
                addFacetChips(chips, 'attributes', group, state.filters.attributes[group], attr ? attr.values : []);
            });
            Object.keys(state.filters.ranges || {}).forEach(function (field) {
                const range = state.filters.ranges[field];
                const label = rangeLabel(field, range);
                chips.push('<button type="button" class="seo-dependiente__chip" data-filter-remove="ranges" data-filter-group="' + escapeAttr(field) + '">' + escapeHtml(label) + '</button>');
            });
            elements.activeFilters.innerHTML = chips.join('');
        }

        function addFacetChips(chips, kind, group, selected, items) {
            (selected || []).forEach(function (slug) {
                const item = (items || []).find(function (candidate) { return candidate.slug === slug; });
                const label = item ? item.label : slug.replace(/-/g, ' ');
                chips.push('<button type="button" class="seo-dependiente__chip" data-filter-remove="' + escapeAttr(kind) + '" data-filter-group="' + escapeAttr(group) + '" data-filter-value="' + escapeAttr(slug) + '">' + escapeHtml(label) + '</button>');
            });
        }

        function rangeLabel(field, range) {
            const labels = { price: 'Precio', weight: 'Peso', length: 'Longitud', width: 'Anchura', height: 'Altura' };
            if (range.min !== undefined && range.max !== undefined) return labels[field] + ': ' + range.min + '–' + range.max;
            if (range.min !== undefined) return labels[field] + ': desde ' + range.min;
            return labels[field] + ': hasta ' + range.max;
        }

        function removeFilter(kind, group, value) {
            if (kind === 'ranges') {
                delete state.filters.ranges[group];
                return;
            }
            if (kind === 'vocabulary' || kind === 'attributes') {
                state.filters[kind][group] = (state.filters[kind][group] || []).filter(function (item) { return item !== value; });
                if (!state.filters[kind][group].length) delete state.filters[kind][group];
                return;
            }
            state.filters[kind] = (state.filters[kind] || []).filter(function (item) { return item !== value; });
        }

        function renderResults(results, data) {
            if (!results.length) {
                const actions = [];
                if (activeFilterCount(state.filters) > 0) {
                    actions.push('<button type="button" class="seo-dependiente__empty-action is-primary" data-dependiente-zero-reset>Quitar filtros y repetir</button>');
                }

                const alternatives = zeroResultAlternatives(data || {});
                alternatives.forEach(function (item) {
                    actions.push('<button type="button" class="seo-dependiente__empty-action" data-dependiente-zero-filter="' + escapeAttr(JSON.stringify(item.filter || {})) + '" data-dependiente-zero-label="' + escapeAttr(item.label || '') + '" data-dependiente-zero-mode="' + escapeAttr(item.mode || 'need') + '">' + escapeHtml(item.text || item.label || 'Explorar') + '</button>');
                });

                const actionsHtml = actions.length ? '<div class="seo-dependiente__empty-actions"><span>También puedes probar:</span><div>' + actions.join('') + '</div></div>' : '';
                elements.results.innerHTML = '<div class="seo-dependiente__empty"><strong>No hay una coincidencia clara</strong><span>' + escapeHtml((config.labels && config.labels.noResults) || 'Prueba con otros términos o elimina un filtro.') + '</span>' + actionsHtml + '</div>';
                return;
            }
            elements.results.innerHTML = results.map(function (product, index) {
                return renderProductCard(product, index + 1);
            }).join('');
        }

        function zeroResultAlternatives(data) {
            const items = [];
            const categories = data && data.facets && Array.isArray(data.facets.categories) ? data.facets.categories : [];
            categories.slice(0, 3).forEach(function (category) {
                if (!category || !category.slug) return;
                items.push({
                    text: 'Explorar ' + String(category.label || category.slug),
                    label: String(category.label || category.slug),
                    mode: 'need',
                    filter: { type: 'categories', slug: category.slug }
                });
            });

            if (!items.length && state.bootstrap && Array.isArray(state.bootstrap.actions)) {
                state.bootstrap.actions.slice(0, 3).forEach(function (action) {
                    if (!action || !action.filter) return;
                    items.push({
                        text: 'Explorar ' + String(action.label || 'otra tarea'),
                        label: String(action.label || ''),
                        mode: 'need',
                        filter: action.filter
                    });
                });
            }

            return items;
        }

        function renderProductCard(product, position) {
            const selected = state.compare.has(Number(product.id));
            const categories = (product.categories || []).join(' · ');
            const kicker = [
                product.brand ? escapeHtml(product.brand) : '',
                categories ? escapeHtml(categories) : '',
                '<span class="seo-dependiente__stock ' + (product.stock_status === 'outofstock' ? 'is-out' : '') + '">' + escapeHtml(product.stock_label || '') + '</span>'
            ].filter(Boolean).join(' · ');
            const reasons = (product.reasons || []).map(function (reason) {
                return '<span class="seo-dependiente__reason">' + escapeHtml(reason) + '</span>';
            }).join('');
            const specs = (product.key_specs || []).map(function (spec) {
                return '<div class="seo-dependiente__spec"><span>' + escapeHtml(spec.label) + '</span><strong>' + escapeHtml(spec.value) + '</strong></div>';
            }).join('');
            return '<article class="seo-dependiente__product-card">' +
                '<a class="seo-dependiente__product-image" data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '"><img src="' + escapeAttr(product.image || config.placeholderImage || '') + '" alt="' + escapeAttr(product.title) + '" loading="lazy"></a>' +
                '<button type="button" class="seo-dependiente__compare-toggle" data-compare-id="' + Number(product.id) + '" aria-pressed="' + (selected ? 'true' : 'false') + '">' + (selected ? 'Seleccionado' : 'Comparar') + '</button>' +
                '<div class="seo-dependiente__product-body">' +
                '<div class="seo-dependiente__product-kicker">' + kicker + '</div>' +
                '<h2 class="seo-dependiente__product-title"><a data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '">' + escapeHtml(product.title) + '</a></h2>' +
                (reasons ? '<div class="seo-dependiente__match-reasons">' + reasons + '</div>' : '') +
                (product.excerpt ? '<p class="seo-dependiente__excerpt">' + escapeHtml(product.excerpt) + '</p>' : '') +
                (specs ? '<div class="seo-dependiente__specs">' + specs + '</div>' : '') +
                '<div class="seo-dependiente__product-foot"><div class="seo-dependiente__price">' + (product.price_html || '') + '</div><a class="seo-dependiente__card-button" data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '">' + escapeHtml((config.labels && config.labels.viewProduct) || 'Ver producto') + '</a></div>' +
                '</div></article>';
        }

        function renderRelated(items, totalResults) {
            if (!elements.related) return;
            if (!items || !items.length) {
                elements.related.hidden = true;
                elements.related.innerHTML = '';
                return;
            }

            const first = items.slice(0, 3);
            const rest = items.slice(3);
            const cards = first.map(renderRelatedCard).join('');
            const more = rest.length ? '<details class="seo-dependiente__related-more"><summary>Ver más información (' + rest.length + ')</summary><div class="seo-dependiente__related-more-list">' + rest.map(renderRelatedCard).join('') + '</div></details>' : '';
            const helpText = totalResults > 0
                ? 'Contenido relacionado con las categorías de los productos que mejor encajan.'
                : 'Aunque no haya un producto exacto, estas guías pueden ayudarte a orientar la búsqueda.';
            elements.related.innerHTML = '<div class="seo-dependiente__related-inner">' +
                '<div class="seo-dependiente__related-head"><span>También te puede ayudar</span><h2>Guías y soluciones</h2><p>' + escapeHtml(helpText) + '</p></div>' +
                '<div class="seo-dependiente__related-list">' + cards + '</div>' + more + '</div>';
            elements.related.hidden = false;
        }

        function renderRelatedCard(item) {
            const image = item.image ? '<span class="seo-dependiente__related-image"><img src="' + escapeAttr(item.image) + '" alt="" loading="lazy" decoding="async"></span>' : '';
            return '<article class="seo-dependiente__related-card">' + image +
                '<div class="seo-dependiente__related-body"><span class="seo-dependiente__related-type">' + escapeHtml(item.type_label || 'Guía') + '</span>' +
                '<h3><a href="' + escapeAttr(item.url || '#') + '">' + escapeHtml(item.title || '') + '</a></h3>' +
                (item.excerpt ? '<p>' + escapeHtml(item.excerpt) + '</p>' : '') +
                '<a class="seo-dependiente__related-link" href="' + escapeAttr(item.url || '#') + '">Leer →</a></div></article>';
        }

        function renderPagination(page, pages) {
            if (pages <= 1) {
                elements.pagination.innerHTML = '';
                return;
            }
            const buttons = [];
            const start = Math.max(1, page - 2);
            const end = Math.min(pages, page + 2);
            if (page > 1) buttons.push(pageButton(page - 1, '←'));
            if (start > 1) buttons.push(pageButton(1, '1'));
            if (start > 2) buttons.push('<span>…</span>');
            for (let i = start; i <= end; i += 1) buttons.push(pageButton(i, String(i), i === page));
            if (end < pages - 1) buttons.push('<span>…</span>');
            if (end < pages) buttons.push(pageButton(pages, String(pages)));
            if (page < pages) buttons.push(pageButton(page + 1, '→'));
            elements.pagination.innerHTML = buttons.join('');
        }

        function pageButton(page, label, active) {
            return '<button type="button" data-page="' + page + '" class="' + (active ? 'is-active' : '') + '"' + (active ? ' aria-current="page"' : '') + '>' + escapeHtml(label) + '</button>';
        }

        function toggleCompare(id) {
            if (!id) return;
            if (state.compare.has(id)) {
                state.compare.delete(id);
            } else if (state.compare.size < Number(config.compareMax || 4)) {
                state.compare.add(id);
            } else {
                elements.status.textContent = 'Puedes comparar un máximo de cuatro productos.';
                return;
            }
            persistCompareIds(state.compare);
            renderCompareTray();
            syncCompareButtons();
        }

        function renderCompareTray() {
            const count = state.compare.size;
            elements.compareTray.hidden = count === 0;
            elements.compareCount.textContent = count + (count === 1 ? ' producto' : ' productos');
            elements.compareOpen.disabled = count < 2;
        }

        function syncCompareButtons() {
            elements.results.querySelectorAll('[data-compare-id]').forEach(function (button) {
                const selected = state.compare.has(Number(button.dataset.compareId));
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                button.textContent = selected ? 'Seleccionado' : 'Comparar';
            });
        }

        async function openComparison() {
            if (state.compare.size < 2) return;
            elements.compareContent.innerHTML = '<div class="seo-dependiente__compare-content"><div class="seo-dependiente__skeleton"></div></div>';
            showDialog();
            try {
                const data = await api('compare', { method: 'POST', body: { ids: Array.from(state.compare) } });
                renderComparison(data);
            } catch (error) {
                elements.compareContent.innerHTML = '<div class="seo-dependiente__compare-content"><div class="seo-dependiente__empty"><strong>No se pudo crear la comparación</strong><span>' + escapeHtml(error.message) + '</span></div></div>';
            }
        }

        function renderComparison(data) {
            const products = data.products || [];
            if (products.length < 2) {
                elements.compareContent.innerHTML = '<div class="seo-dependiente__compare-content"><div class="seo-dependiente__empty"><strong>Faltan productos disponibles</strong><span>Selecciona otras opciones.</span></div></div>';
                return;
            }
            const criteria = data.criteria || {};
            const criteriaHtml = criteria.labels && criteria.labels.length ? '<div class="seo-dependiente__criteria"><strong>' + escapeHtml(criteria.title || 'Qué conviene comprobar') + '</strong>' + criteria.labels.map(function (label) { return '<span class="seo-dependiente__chip">' + escapeHtml(label) + '</span>'; }).join('') + '</div>' : '';
            const head = '<thead><tr><th>Criterio</th>' + products.map(function (product) {
                return '<th><div class="seo-dependiente__comparison-product"><img src="' + escapeAttr(product.image || config.placeholderImage || '') + '" alt=""><a href="' + escapeAttr(product.url) + '">' + escapeHtml(product.title) + '</a></div></th>';
            }).join('') + '</tr></thead>';
            const body = '<tbody>' + (data.rows || []).map(function (row) {
                const classes = [row.different ? 'is-different' : '', row.priority ? 'is-priority' : ''].filter(Boolean).join(' ');
                return '<tr class="' + classes + '"><th class="seo-dependiente__comparison-label">' + escapeHtml(row.label) + (row.priority ? '<small>Prioridad de compra</small>' : '') + '</th>' + products.map(function (product) {
                    const value = row.values && row.values[String(product.id)] ? row.values[String(product.id)] : '—';
                    return '<td>' + escapeHtml(value) + '</td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody>';
            elements.compareContent.innerHTML = '<div class="seo-dependiente__compare-content">' + criteriaHtml + '<div class="seo-dependiente__comparison-wrap"><table class="seo-dependiente__comparison">' + head + body + '</table></div></div>';
        }

        function showDialog() {
            if (typeof elements.dialog.showModal === 'function') {
                if (!elements.dialog.open) elements.dialog.showModal();
            } else {
                elements.dialog.setAttribute('open', 'open');
            }
        }

        function closeDialog() {
            if (typeof elements.dialog.close === 'function' && elements.dialog.open) {
                elements.dialog.close();
            } else {
                elements.dialog.removeAttribute('open');
            }
        }

        function updateUrl() {
            if (!window.history || !window.history.replaceState) return;
            const url = new URL(window.location.href);
            if (state.q) url.searchParams.set('dep_q', state.q);
            else url.searchParams.delete('dep_q');
            window.history.replaceState({}, '', url.toString());
        }
    }

    function activeFilterCount(filters) {
        filters = filters || emptyFilters();
        let count = 0;
        ['categories', 'tags', 'brands', 'stock'].forEach(function (key) {
            count += Array.isArray(filters[key]) ? filters[key].length : 0;
        });
        Object.keys(filters.vocabulary || {}).forEach(function (key) {
            count += Array.isArray(filters.vocabulary[key]) ? filters.vocabulary[key].length : 0;
        });
        Object.keys(filters.attributes || {}).forEach(function (key) {
            count += Array.isArray(filters.attributes[key]) ? filters.attributes[key].length : 0;
        });
        Object.keys(filters.ranges || {}).forEach(function (key) {
            const range = filters.ranges[key] || {};
            if (range.min !== undefined && range.min !== null && range.min !== '') count += 1;
            if (range.max !== undefined && range.max !== null && range.max !== '') count += 1;
        });
        return count;
    }

    function emptyFilters() {
        return { categories: [], tags: [], brands: [], stock: [], vocabulary: {}, attributes: {}, ranges: {} };
    }

    async function api(path, options) {
        const settings = options || {};
        const fetchOptions = {
            method: settings.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }
        };
        if (settings.body) fetchOptions.body = JSON.stringify(settings.body);
        const response = await fetch(String(config.root || '') + path, fetchOptions);
        let payload = null;
        try { payload = await response.json(); } catch (error) { payload = null; }
        if (!response.ok) {
            throw new Error(payload && payload.message ? payload.message : 'Error de comunicación con el catálogo.');
        }
        return payload;
    }

    function loadCompareIds() {
        try {
            const values = JSON.parse(window.sessionStorage.getItem('seoDependienteCompare') || '[]');
            return new Set((Array.isArray(values) ? values : []).map(Number).filter(Boolean).slice(0, Number(config.compareMax || 4)));
        } catch (error) {
            return new Set();
        }
    }

    function persistCompareIds(set) {
        try { window.sessionStorage.setItem('seoDependienteCompare', JSON.stringify(Array.from(set))); } catch (error) { /* storage can be unavailable */ }
    }

    function numberFormat(value) {
        return new Intl.NumberFormat(document.documentElement.lang || 'es-ES').format(Number(value || 0));
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }
}());
