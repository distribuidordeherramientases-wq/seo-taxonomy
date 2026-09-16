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
            amazon: root.querySelector('[data-dependiente-amazon]'),
            feedback: root.querySelector('[data-dependiente-feedback]'),
            compareTray: root.querySelector('[data-dependiente-compare-tray]'),
            compareCount: root.querySelector('[data-dependiente-compare-count]'),
            compareClear: root.querySelector('[data-dependiente-compare-clear]'),
            compareOpen: root.querySelector('[data-dependiente-compare-open]'),
            dialog: root.querySelector('[data-dependiente-dialog]'),
            dialogClose: root.querySelector('[data-dependiente-dialog-close]'),
            compareContent: root.querySelector('[data-dependiente-compare-content]'),
            help: root.querySelector('[data-dependiente-help]'),
            helpToggle: root.querySelector('[data-dependiente-help-toggle]'),
            helpPanel: root.querySelector('[data-dependiente-help-panel]'),
            helpForm: root.querySelector('[data-dependiente-help-form]'),
            helpStatus: root.querySelector('[data-dependiente-help-status]'),
            helpTitle: root.querySelector('[data-dependiente-help-title]'),
            helpText: root.querySelector('[data-dependiente-help-text]')
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
            interpreterDebug: null,
            bootstrap: null,
            loading: false,
            searchId: '',
            semanticHint: null,
            semanticHints: [],
            clarification: null,
            clarificationTimer: null,
            feedbackTimer: null,
            hasResultInteraction: false,
            helpSubmitting: false,
            amazonRequestId: 0,
            compare: loadCompareIds()
        };

        bindEvents();
        removeDefaultExplorationSections();
        loadBootstrap();
        renderCompareTray();

        function assistantAvatarHtml(extraClass) {
            const classes = 'seo-dependiente__assistant-avatar' + (extraClass ? ' ' + extraClass : '');
            return '<span class="' + classes + '" aria-hidden="true"><span>👤</span></span>';
        }

        const initialParams = new URLSearchParams(window.location.search);
        const initialQuery = initialParams.get('dep_q');
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
                // filtro/plataforma Milwaukee a "se me ha roto un grifo". El rol
                // seleccionado en el buscador se conserva porque forma parte de la
                // nueva consulta escrita.
                if (nextQuery && (state.modeSource === 'menu' || state.modeSource === 'refine')) {
                    state.filters = emptyFilters();
                    state.orderby = 'relevance';
                    if (elements.sort) elements.sort.value = 'relevance';
                    setMode('need', 'default');
                }

                state.q = nextQuery;
                state.contextLabel = '';
                state.semanticHint = null;
                state.semanticHints = [];
                state.page = 1;
                search(true);
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
                const helpOpen = event.target.closest('[data-dependiente-help-open]');
                if (helpOpen) {
                    event.preventDefault();
                    openHelp(true);
                    return;
                }

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
                    confirmCatalogAlternative(alternative, filter);
                    return;
                }

                const discoveryFilter = event.target.closest('[data-dependiente-discovery-filter]');
                if (discoveryFilter) {
                    event.preventDefault();
                    let filter = {};
                    try { filter = JSON.parse(discoveryFilter.dataset.dependienteDiscoveryFilter || '{}'); } catch (error) { filter = {}; }
                    applyCardFilter(filter);
                    state.contextLabel = discoveryFilter.dataset.dependienteDiscoveryLabel || state.contextLabel || '';
                    state.page = 1;
                    search(false);
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

            if (elements.helpToggle) {
                elements.helpToggle.addEventListener('click', function () {
                    if (!elements.helpPanel) return;
                    if (elements.helpPanel.hidden) openHelp(true);
                    else closeHelp();
                });
            }

            if (elements.helpForm) {
                elements.helpForm.addEventListener('submit', submitHelpRequest);
            }

            if (elements.feedback) {
                elements.feedback.addEventListener('click', function (event) {
                    const button = event.target.closest('[data-dependiente-feedback-value]');
                    if (!button) return;
                    event.preventDefault();
                    submitHelpfulFeedback(Number(button.dataset.dependienteFeedbackValue || 0));
                });
            }

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
                // Los antiguos escaparates generales (tipo de tarea / herramienta
                // o sistema) no participan en la consulta y se han retirado de la
                // experiencia. Dependiente muestra solo opciones nacidas de la
                // busqueda actual.
                removeDefaultExplorationSections();
            } catch (error) {
                removeDefaultExplorationSections();
            }
        }

        function removeDefaultExplorationSections() {
            const headings = root.querySelectorAll('h1,h2,h3,h4,h5');
            headings.forEach(function (heading) {
                const text = String(heading.textContent || '').trim().toLowerCase();
                if (text !== 'explora por tipo de tarea' && text !== 'explora por herramienta o sistema') {
                    return;
                }
                const section = heading.closest('section') || heading.parentElement;
                if (section && section !== root) {
                    section.remove();
                } else {
                    heading.remove();
                }
            });

            [elements.actions, elements.tools].forEach(function (container) {
                if (!container || !container.isConnected) return;
                const section = container.closest('section');
                if (section && section !== root) {
                    section.remove();
                } else {
                    container.hidden = true;
                    container.innerHTML = '';
                }
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
                    state.semanticHints = [];
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
                    setSolutionRole('');
                    applyCardFilter(filter);
                    state.contextLabel = cards[index] ? cards[index].label : '';
                    state.q = '';
                    state.semanticHint = null;
                    state.semanticHints = [];
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
            if (filter.type === 'brands') state.filters.brands = [slug];
            if (filter.type === 'vocabulary' && filter.group) state.filters.vocabulary[filter.group] = [slug];
            if (filter.type === 'attributes' && filter.group) state.filters.attributes[filter.group] = [slug];
        }

        function setMode(mode, source) {
            // Los antiguos modos siguen existiendo internamente para la navegación
            // visual secundaria, pero el buscador principal queda en texto libre.
            state.mode = mode;
            if (source) state.modeSource = source;
        }

        async function search(resetScroll) {
            if (state.loading) return;
            clearClarificationTimer();
            removeClarification();
            clearFeedbackTimer();
            clearFeedbackPrompt();
            state.hasResultInteraction = false;
            state.loading = true;
            elements.workspace.hidden = false;
            if (elements.related) {
                elements.related.hidden = true;
                elements.related.innerHTML = '';
            }
            const previousCandidates = root.querySelector('[data-dependiente-candidate-products]');
            if (previousCandidates) {
                previousCandidates.hidden = true;
                previousCandidates.innerHTML = '';
            }
            state.amazonRequestId += 1;
            clearAmazon();
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
                        semantic_hint: state.semanticHint || null,
                        semantic_hints: Array.isArray(state.semanticHints) ? state.semanticHints : []
                    }
                });
                state.facets = data.facets || null;
                state.interpreterDebug = data.interpreter_debug || null;
                state.searchId = String(data.search_id || '');
                if (Array.isArray(data.semantic_hints)) {
                    state.semanticHints = data.semantic_hints.slice(0, 2);
                    state.semanticHint = state.semanticHints.length ? state.semanticHints[state.semanticHints.length - 1] : null;
                }
                updateHelpPrompt(data);
                renderSummary(data);
                renderFilters(data.facets || {});
                renderActiveFilters();
                renderResults(data.results || [], data);
                renderCandidateProducts(data.candidate_products || []);
                renderRelated(data.related || [], Number(data.total || 0));
                renderPagination(data.page || 1, data.pages || 0, (data.results || []).length);
                loadAmazonFallback(data.external_fallback || null);
                state.clarification = data.clarification || null;
                scheduleClarification(state.clarification);
                scheduleFeedbackPrompt(data, state.clarification);
                elements.status.textContent = data.truncated ? 'He encontrado muchas coincidencias. Añade una medida, marca, compatibilidad o uso para afinar mejor.' : '';
                updateUrl();
            } catch (error) {
                updateHelpPrompt({ error: true, total: 0, search_strategy: 'index_unavailable' });
                elements.summary.innerHTML = '<strong>No he podido terminar la búsqueda.</strong>';
                elements.status.textContent = error.message || (config.labels && config.labels.error) || 'Ha ocurrido un error.';
                elements.results.innerHTML = '<div class="seo-dependiente__empty"><strong>Prueba de nuevo</strong><span>Comprueba la conexión o simplifica la consulta.</span><div class="seo-dependiente__empty-help"><button type="button" class="seo-dependiente__empty-action" data-dependiente-help-open>Pedir ayuda con esta búsqueda</button><small>Podemos revisar el recorrido que has hecho y responderte por correo.</small></div></div>';
                if (elements.related) {
                    elements.related.hidden = true;
                    elements.related.innerHTML = '';
                }
                const candidateBlock = root.querySelector('[data-dependiente-candidate-products]');
                if (candidateBlock) {
                    candidateBlock.hidden = true;
                    candidateBlock.innerHTML = '';
                }
                clearAmazon();
                clearFeedbackPrompt();
            } finally {
                state.loading = false;
                syncCompareButtons();
            }
        }

        function clearFeedbackTimer() {
            if (state.feedbackTimer) {
                window.clearTimeout(state.feedbackTimer);
                state.feedbackTimer = null;
            }
        }

        function clearFeedbackPrompt() {
            if (!elements.feedback) return;
            elements.feedback.hidden = true;
            elements.feedback.innerHTML = '';
        }

        function scheduleFeedbackPrompt(data, clarification) {
            clearFeedbackTimer();
            clearFeedbackPrompt();
            if (!elements.feedback || !state.searchId) return;

            // Si antes necesitamos desambiguar, esa pregunta tiene prioridad.
            // Tras responderla se hace una nueva busqueda y entonces pedimos valoracion.
            if (clarification && clarification.should_ask && Array.isArray(clarification.options) && clarification.options.length >= 2) {
                return;
            }

            const searchId = state.searchId;
            state.feedbackTimer = window.setTimeout(function () {
                state.feedbackTimer = null;
                if (!state.searchId || state.searchId !== searchId || state.loading) return;
                renderFeedbackPrompt();
            }, 4500);
        }

        function renderFeedbackPrompt() {
            if (!elements.feedback || !state.searchId) return;
            elements.feedback.hidden = false;
            elements.feedback.innerHTML = assistantAvatarHtml('seo-dependiente__assistant-avatar--message') +
                '<div class="seo-dependiente__feedback-copy"><strong>¿Te ha servido esta respuesta?</strong><span>Tu valoración nos ayuda a mejorar qué entiende el Dependiente y cuándo debe buscar alternativas.</span></div>' +
                '<div class="seo-dependiente__feedback-actions">' +
                '<button type="button" data-dependiente-feedback-value="1">Sí</button>' +
                '<button type="button" data-dependiente-feedback-value="-1">No</button>' +
                '</div>';
        }

        function submitHelpfulFeedback(value) {
            if (!elements.feedback || !state.searchId || !value) return;
            clearFeedbackTimer();
            sendFeedbackEvent({
                search_id: state.searchId,
                event: 'helpful',
                value: value > 0 ? 1 : -1
            });
            elements.feedback.hidden = false;
            elements.feedback.innerHTML = assistantAvatarHtml('seo-dependiente__assistant-avatar--message') + '<div class="seo-dependiente__feedback-thanks"><strong>Gracias.</strong><span>He guardado tu valoración para mejorar las próximas búsquedas.</span></div>';
        }

        function updateHelpPrompt(data) {
            if (!elements.help) return;
            const strategy = String((data && data.search_strategy) || '');
            const weakStrategies = ['broad_fallback', 'catalog_fallback', 'index_unavailable'];
            const total = Number(data && data.total !== undefined ? data.total : -1);
            const prominent = Boolean(data && data.error) || total === 0 || weakStrategies.indexOf(strategy) !== -1;

            elements.help.classList.toggle('is-prominent', prominent);
            if (elements.helpTitle) {
                elements.helpTitle.textContent = prominent
                    ? '¿No hemos encontrado lo que necesitas?'
                    : '¿No encuentras lo que buscas?';
            }
            if (elements.helpText) {
                elements.helpText.textContent = prominent
                    ? 'Pídenos ayuda. Revisaremos esta búsqueda con su recorrido completo y te responderemos por correo.'
                    : 'Podemos revisar tu búsqueda con todo el contexto y responderte por correo.';
            }
        }

        function openHelp(focusEmail) {
            if (!elements.helpPanel || !elements.helpToggle) return;
            elements.helpPanel.hidden = false;
            elements.helpToggle.setAttribute('aria-expanded', 'true');
            elements.helpToggle.textContent = 'Cerrar';
            if (elements.help) elements.help.classList.add('is-open');
            if (focusEmail && elements.helpForm) {
                const email = elements.helpForm.querySelector('input[name="help_email"]');
                window.setTimeout(function () {
                    if (email) email.focus({ preventScroll: true });
                    if (elements.help && typeof elements.help.scrollIntoView === 'function') {
                        elements.help.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }, 40);
            }
        }

        function closeHelp() {
            if (!elements.helpPanel || !elements.helpToggle) return;
            elements.helpPanel.hidden = true;
            elements.helpToggle.setAttribute('aria-expanded', 'false');
            elements.helpToggle.textContent = 'Pedir ayuda';
            if (elements.help) elements.help.classList.remove('is-open');
        }

        async function submitHelpRequest(event) {
            event.preventDefault();
            if (!elements.helpForm || state.helpSubmitting) return;

            const emailInput = elements.helpForm.querySelector('input[name="help_email"]');
            const noteInput = elements.helpForm.querySelector('textarea[name="help_note"]');
            const websiteInput = elements.helpForm.querySelector('input[name="website"]');
            const submitButton = elements.helpForm.querySelector('[data-dependiente-help-submit]');

            if (!emailInput || !emailInput.value.trim()) {
                if (emailInput && typeof emailInput.reportValidity === 'function') emailInput.reportValidity();
                return;
            }
            if (typeof elements.helpForm.reportValidity === 'function' && !elements.helpForm.reportValidity()) return;

            state.helpSubmitting = true;
            if (submitButton) submitButton.disabled = true;
            if (elements.helpStatus) {
                elements.helpStatus.classList.remove('is-success', 'is-error');
                elements.helpStatus.textContent = 'Enviando la consulta con el contexto de esta búsqueda…';
            }

            try {
                const data = await api('help-request', {
                    method: 'POST',
                    body: {
                        search_id: state.searchId || '',
                        email: emailInput.value.trim(),
                        note: noteInput ? noteInput.value.trim() : '',
                        query: state.q || (elements.query ? elements.query.value.trim() : ''),
                        mode: state.mode,
                        context_label: state.contextLabel || '',
                        page_url: window.location.href,
                        filters: state.filters || emptyFilters(),
                        semantic_hint: state.semanticHint || null,
                        semantic_hints: Array.isArray(state.semanticHints) ? state.semanticHints : [],
                        orderby: state.orderby || 'relevance',
                        compare_ids: Array.from(state.compare || []),
                        website: websiteInput ? websiteInput.value : ''
                    }
                });
                if (elements.helpStatus) {
                    elements.helpStatus.classList.add('is-success');
                    elements.helpStatus.textContent = data && data.message
                        ? data.message
                        : 'Solicitud enviada. Te responderemos por correo.';
                }
                if (elements.help) elements.help.classList.add('is-sent');
                if (noteInput) noteInput.value = '';
            } catch (error) {
                if (elements.helpStatus) {
                    elements.helpStatus.classList.add('is-error');
                    elements.helpStatus.textContent = error.message || 'No he podido enviar la solicitud. Inténtalo de nuevo.';
                }
            } finally {
                state.helpSubmitting = false;
                if (submitButton) submitButton.disabled = false;
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
            const step = Math.max(1, Number(clarification.step || (state.semanticHints.length + 1) || 1));
            const maxSteps = Math.max(step, Number(clarification.max_steps || 2));
            const reduction = Math.max(0, Number(clarification.estimated_reduction || 0));
            const options = (clarification.options || []).map(function (option) {
                const count = Number(option.count || 0);
                const countText = count > 0 ? '<small>' + numberFormat(count) + ' opciones</small>' : '';
                return '<button type="button" class="seo-dependiente__clarification-option" data-dependiente-clarify' +
                    ' data-role="' + escapeAttr(option.role || clarification.role || 'term') + '"' +
                    ' data-value="' + escapeAttr(option.value || '') + '"' +
                    ' data-label="' + escapeAttr(option.label || option.value || '') + '"' +
                    ' data-source="' + escapeAttr(option.source || 'closed_option') + '"' +
                    ' data-source-group="' + escapeAttr(option.source_group || '') + '"' +
                    ' data-source-slug="' + escapeAttr(option.source_slug || '') + '"' +
                    ' data-filter="' + escapeAttr(JSON.stringify(option.filter || {})) + '">' +
                    '<span>' + escapeHtml(option.label || option.value || '') + '</span>' + countText + '</button>';
            }).join('');

            const reductionText = reduction >= 20
                ? '<span class="seo-dependiente__clarification-impact">Esta respuesta puede descartar aprox. ' + escapeHtml(String(reduction)) + '% de opciones.</span>'
                : '<span class="seo-dependiente__clarification-impact">Con esta respuesta puedo orientar mejor la búsqueda.</span>';
            const html = '<section class="seo-dependiente__clarification" data-dependiente-clarification role="region" aria-live="polite" aria-label="Pregunta del Intérprete">' +
                assistantAvatarHtml('seo-dependiente__assistant-avatar--message') +
                '<div class="seo-dependiente__assistant-message">' +
                '<div class="seo-dependiente__clarification-head"><span>Antes de afinar los productos</span><small>Pregunta ' + step + ' de hasta ' + maxSteps + '</small></div>' +
                '<strong>' + escapeHtml(clarification.question || '¿Puedes concretar un poco más?') + '</strong>' +
                '<p>Elige una opción. Añadiré esa información a la consulta limpia que recibe el Dependiente.</p>' +
                '<div class="seo-dependiente__clarification-options">' + options +
                '<button type="button" class="seo-dependiente__clarification-option is-other" data-dependiente-clarify-other><span>Otro</span><small>Escribir solo si ninguna encaja</small></button></div>' +
                reductionText +
                '<div data-dependiente-clarify-other-slot></div>' +
                '</div></section>';
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

        function rememberSemanticHint(hint) {
            if (!hint || !hint.value) return;
            const cleanHint = {
                role: hint.role || 'term',
                value: hint.value || '',
                label: hint.label || hint.value || '',
                source: hint.source || 'clarification',
                source_group: hint.source_group || '',
                source_slug: hint.source_slug || ''
            };
            state.semanticHint = cleanHint;
            const key = [cleanHint.role || '', cleanHint.value || '', cleanHint.source_group || ''].join('|');
            state.semanticHints = (Array.isArray(state.semanticHints) ? state.semanticHints : []).filter(function (known) {
                return [known.role || '', known.value || '', known.source_group || ''].join('|') !== key;
            });
            state.semanticHints.push(cleanHint);
            // La conversación está limitada a dos aclaraciones. Conservamos ambas
            // confirmaciones para que la segunda nunca borre la primera.
            state.semanticHints = state.semanticHints.slice(-2);
        }

        function confirmCatalogAlternative(button, filter) {
            const originalSearchId = state.searchId;
            const label = button.dataset.dependienteZeroLabel || button.textContent || '';
            const value = button.dataset.dependienteZeroValue || (filter && filter.slug) || '';
            const role = button.dataset.dependienteZeroRole || 'object';
            const sourceGroup = button.dataset.dependienteZeroGroup || (filter && filter.group) || ((filter && filter.type === 'categories') ? 'category' : (filter && filter.type) || '');
            const sourceSlug = button.dataset.dependienteZeroSlug || (filter && filter.slug) || '';

            state.hasResultInteraction = true;
            clearClarificationTimer();

            // Una alternativa sugerida por el catálogo es una precisión de la
            // conversación, no una búsqueda nueva. Conservamos q, filtros previos y
            // respuestas confirmadas; solo añadimos/reemplazamos el eje elegido.
            if (filter && filter.slug) {
                applyCardFilter(filter);
            }
            if (value) {
                rememberSemanticHint({
                    role: role,
                    value: value,
                    label: label,
                    source: button.dataset.dependienteZeroSource || 'catalog_alternative',
                    source_group: sourceGroup,
                    source_slug: sourceSlug
                });
            }
            if (label) {
                state.contextLabel = state.contextLabel ? state.contextLabel + ' · ' + label : label;
            }
            state.page = 1;
            setMode(button.dataset.dependienteZeroMode || state.mode || 'need', 'refine');

            if (originalSearchId && value) {
                sendFeedbackEvent({
                    search_id: originalSearchId,
                    event: 'clarify',
                    role: role,
                    choice_value: value,
                    label: label,
                    source: button.dataset.dependienteZeroSource || 'catalog_alternative',
                    source_group: sourceGroup,
                    source_slug: sourceSlug,
                    clarification_step: Math.min(2, Math.max(1, state.semanticHints.length)),
                    clarification_axis: sourceGroup,
                    is_other: 0
                });
            }
            search(false);
        }

        function confirmClarification(option, filter, isOther) {
            const originalSearchId = state.searchId;
            if (!originalSearchId) return;
            state.hasResultInteraction = true;
            clearClarificationTimer();
            sendClarificationFeedback(originalSearchId, option, Boolean(isOther));

            // Una respuesta del Intérprete mejora el lenguaje de la consulta.
            // No abre categorías ni aplica filtros duros: el Dependiente decide
            // después cómo resolver esas palabras dentro de su catálogo.
            rememberSemanticHint({
                role: option.role,
                value: option.value,
                label: option.label,
                source: option.source,
                source_group: option.source_group || '',
                source_slug: option.source_slug || ''
            });
            setMode(state.mode || 'need', 'refine');
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
                options: clarification.options || [],
                clarification_step: Number(clarification.step || 1),
                clarification_axis: clarification.axis || '',
                clarification_strategy: clarification.strategy || ''
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
                clarification_step: Number((state.clarification && state.clarification.step) || 1),
                clarification_axis: (state.clarification && state.clarification.axis) || '',
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
            const interpreterLine = '';
            let subject = state.q ? 'para “' + escapeHtml(state.q) + '”' : (state.contextLabel ? 'para ' + escapeHtml(state.contextLabel) : 'con los criterios elegidos');
            if (!total) {
                elements.summary.innerHTML = '<span class="seo-dependiente__summary-copy"><span><strong>Estoy ampliando la búsqueda</strong> ' + subject + '. Puedes añadir un detalle para afinarla.</span>' + interpreterLine + '</span>';
                return;
            }
            const noun = total === 1 ? 'opción' : 'opciones';
            elements.summary.innerHTML = '<span class="seo-dependiente__summary-copy"><span><strong>He encontrado ' + numberFormat(total) + ' ' + noun + '</strong> ' + subject + '. Te muestro primero las que mejor encajan.</span>' + interpreterLine + '</span>';
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
            groups.push(renderInterpreterDebug(state.interpreterDebug));

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

        function renderInterpreterDebug(debug) {
            if (!debug || !debug.enabled) return '';
            const removed = Array.isArray(debug.removed_tokens) ? debug.removed_tokens.filter(Boolean) : [];
            const transformations = Array.isArray(debug.transformations) ? debug.transformations.filter(Boolean) : [];
            const assist = Array.isArray(debug.assist_terms) ? debug.assist_terms.filter(Boolean) : [];
            const structured = debug.structured && typeof debug.structured === 'object' ? debug.structured : {};
            const actions = Array.isArray(structured.actions) ? structured.actions : [];
            const related = Array.isArray(structured.related_concepts) ? structured.related_concepts : [];
            const context = Array.isArray(structured.context) ? structured.context.filter(Boolean) : [];
            const groups = structured.semantic_groups && typeof structured.semantic_groups === 'object' ? structured.semantic_groups : {};
            const confidence = Math.round(Number(debug.confidence || 0) * 100);

            const actionText = actions.length ? actions.map(function (item) {
                const canonical = item && item.canonical_action ? String(item.canonical_action) : '';
                const relatedTerm = item && item.related_concept ? String(item.related_concept) : '';
                const surface = item && item.surface ? String(item.surface) : '';
                let text = surface || canonical;
                if (canonical && canonical !== surface) text += ' → ' + canonical;
                if (relatedTerm) text += ' → concepto ' + relatedTerm;
                return text;
            }).filter(Boolean).join(' | ') : '—';

            const semanticLines = ['rol','tipo','aplicacion','plataforma','subtipo','category','tag'].map(function (group) {
                const items = Array.isArray(groups[group]) ? groups[group] : [];
                if (!items.length) return '';
                const label = group === 'category' ? 'categoría' : (group === 'tag' ? 'etiqueta' : group);
                const values = items.map(function (item) { return item && (item.target || item.term) ? String(item.target || item.term) : ''; }).filter(Boolean);
                return '<div><b>' + escapeHtml(label.toUpperCase()) + ':</b> ' + escapeHtml(values.join(' · ')) + '</div>';
            }).filter(Boolean).join('');

            const relatedText = related.length ? related.map(function (item) { return item && item.term ? String(item.term) : ''; }).filter(Boolean).join(' · ') : '—';
            const dep = debug.dependiente && typeof debug.dependiente === 'object' ? debug.dependiente : {};
            const depGroups = Array.isArray(dep.groups) ? dep.groups : [];
            const depPrimary = Array.isArray(dep.primary_groups) ? dep.primary_groups : [];
            const depFields = Array.isArray(dep.catalog_fields) ? dep.catalog_fields : [];
            const depRoutes = Array.isArray(dep.semantic_routes) ? dep.semantic_routes : [];
            const depMatches = Array.isArray(dep.top_matches) ? dep.top_matches : [];
            const depUnfilteredRows = Array.isArray(dep.unfiltered_primary_rows) ? dep.unfiltered_primary_rows : [];
            const depAssist = dep.assist_profile && typeof dep.assist_profile === 'object' ? dep.assist_profile : {};
            const depIdentity = Array.isArray(depAssist.identity_terms) ? depAssist.identity_terms.filter(Boolean) : [];
            const depVocabulary = Array.isArray(depAssist.vocabulary_terms) ? depAssist.vocabulary_terms.filter(Boolean) : [];
            const depActions = Array.isArray(depAssist.action_terms) ? depAssist.action_terms.filter(Boolean) : [];
            const depContext = Array.isArray(depAssist.context_terms) ? depAssist.context_terms.filter(Boolean) : [];
            const roleLabels = {intent:'intención', object:'objeto', state:'estado', term:'término', material:'material', tool:'herramienta', action:'acción'};
            const depGroupText = depGroups.length ? depGroups.map(function (group) {
                const role = group && group.role ? String(group.role) : 'term';
                const variants = group && Array.isArray(group.variants) ? group.variants.filter(Boolean) : [];
                const canonical = group && group.canonical ? String(group.canonical) : '';
                const values = variants.length ? variants.join(' / ') : canonical;
                return (roleLabels[role] || role) + ': ' + values;
            }).filter(Boolean).join(' | ') : '—';
            const depPrimaryText = depPrimary.length ? depPrimary.map(function (variants) {
                return Array.isArray(variants) ? variants.filter(Boolean).join(' / ') : '';
            }).filter(Boolean).join(' | ') : '—';
            const depRoutesText = depRoutes.length ? depRoutes.map(function (route) {
                const group = route && route.group ? String(route.group).toUpperCase() : 'RUTA';
                const term = route && route.term ? String(route.term) : '';
                const role = route && route.role ? ' (' + String(route.role) + ')' : '';
                return group + ': ' + term + role;
            }).filter(Boolean).join(' | ') : '—';
            const depMatchesHtml = depMatches.length ? '<div style="margin-top:5px"><b>Primeros productos puntuados:</b>' + depMatches.map(function (item) {
                const reasons = item && Array.isArray(item.reasons) ? item.reasons.filter(Boolean).join(', ') : '';
                const score = item && item.score !== undefined ? String(item.score) : '0';
                const coverage = item && item.coverage !== undefined ? String(item.coverage) : '0';
                const identityHits = item && item.identity_hits !== undefined ? String(item.identity_hits) : '0';
                const identitySources = item && Array.isArray(item.identity_sources) ? item.identity_sources.filter(Boolean).join(' | ') : '';
                const vocabularyHits = item && item.vocabulary_hits !== undefined ? String(item.vocabulary_hits) : '0';
                const actionHits = item && item.action_hits !== undefined ? String(item.action_hits) : '0';
                return '<div style="margin-left:8px">• ' + escapeHtml(String(item.title || ('#' + String(item.id || '')))) + ' · score ' + escapeHtml(score) + ' · cobertura ' + escapeHtml(coverage) + ' · identidad ' + escapeHtml(identityHits) + (identitySources ? ' [' + escapeHtml(identitySources) + ']' : '') + ' · vocab ' + escapeHtml(vocabularyHits) + ' · acción ' + escapeHtml(actionHits) + (reasons ? ' · ' + escapeHtml(reasons) : '') + '</div>';
            }).join('') + '</div>' : '';

            // Tabla STAGING de TODAS las filas de la primera pasada. No usa
            // score, elegibilidad, `válidos`, filtros ni serialización de tarjetas.
            const depUnfilteredHtml = depUnfilteredRows.length ?
                '<details open style="margin-top:10px"><summary style="cursor:pointer;font-weight:700">PRODUCTOS DEL ÍNDICE SIN FILTRAR (' + escapeHtml(String(depUnfilteredRows.length)) + ')</summary>' +
                '<div style="margin:6px 0 5px"><b>Sin filtrar:</b> estas son exactamente las filas de la primera pasada antes de cualquier validación o ranking.</div>' +
                '<div style="max-height:520px;overflow:auto;border:1px solid #d9dfda;border-radius:7px;background:#fff">' +
                '<table style="width:100%;border-collapse:collapse;font-size:11px;line-height:1.35"><thead><tr>' +
                '<th style="position:sticky;top:0;background:#eef3ef;padding:5px;text-align:left;border-bottom:1px solid #d9dfda">#</th>' +
                '<th style="position:sticky;top:0;background:#eef3ef;padding:5px;text-align:left;border-bottom:1px solid #d9dfda">ID</th>' +
                '<th style="position:sticky;top:0;background:#eef3ef;padding:5px;text-align:left;border-bottom:1px solid #d9dfda">Producto</th>' +
                '<th style="position:sticky;top:0;background:#eef3ef;padding:5px;text-align:left;border-bottom:1px solid #d9dfda">Categorías del índice</th>' +
                '</tr></thead><tbody>' + depUnfilteredRows.map(function (item) {
                    const categories = item && Array.isArray(item.categories) ? item.categories.filter(Boolean).join(' · ') : '';
                    return '<tr>' +
                        '<td style="padding:5px;border-bottom:1px solid #edf0ed;vertical-align:top">' + escapeHtml(String(item.position || '')) + '</td>' +
                        '<td style="padding:5px;border-bottom:1px solid #edf0ed;vertical-align:top">' + escapeHtml(String(item.id || '')) + '</td>' +
                        '<td style="padding:5px;border-bottom:1px solid #edf0ed;vertical-align:top;font-weight:600">' + escapeHtml(String(item.title || '')) + '</td>' +
                        '<td style="padding:5px;border-bottom:1px solid #edf0ed;vertical-align:top">' + escapeHtml(categories || '—') + '</td>' +
                    '</tr>';
                }).join('') + '</tbody></table></div></details>' : '';
            return '<div class="seo-dependiente__interpreter-log" style="margin:14px 0;padding:12px;border:1px dashed #9aa59d;border-radius:10px;background:#f8faf8;font-size:12px;line-height:1.45;overflow-wrap:anywhere">' +
                '<strong style="display:block;margin-bottom:7px">LOG INTÉRPRETE · STAGING</strong>' +
                '<div><b>Cliente:</b> ' + escapeHtml(String(debug.raw_query || '—')) + '</div>' +
                '<div><b>Texto filtrado:</b> ' + escapeHtml(String(debug.filtered_query || '—')) + '</div>' +
                '<div><b>Acción:</b> ' + escapeHtml(actionText) + '</div>' +
                semanticLines +
                '<div><b>Conceptos relacionados:</b> ' + escapeHtml(relatedText) + '</div>' +
                '<div><b>Contexto sin clasificar:</b> ' + escapeHtml(context.length ? context.join(' · ') : '—') + '</div>' +
                '<div><b>Señales añadidas:</b> ' + escapeHtml(assist.length ? assist.join(' · ') : '—') + '</div>' +
                '<div><b>Dependiente recibe:</b> ' + escapeHtml(String(debug.dependiente_query || '—')) + '</div>' +
                '<div><b>Ruido quitado:</b> ' + escapeHtml(removed.length ? removed.join(' · ') : '—') + '</div>' +
                '<div><b>Transformaciones:</b> ' + escapeHtml(transformations.length ? transformations.join(' | ') : '—') + '</div>' +
                '<div><b>Confianza:</b> ' + escapeHtml(String(confidence)) + '% · <b>Modo:</b> asistencia lingüística activa</div>' +
                '<div style="height:1px;background:#d9dfda;margin:9px 0"></div>' +
                '<strong style="display:block;margin-bottom:5px">LOG DEPENDIENTE · STAGING</strong>' +
                '<div><b>Consulta efectiva:</b> ' + escapeHtml(String(dep.query || debug.dependiente_query || '—')) + '</div>' +
                '<div><b>Grupos que busca:</b> ' + escapeHtml(depGroupText) + '</div>' +
                '<div><b>Primera pasada al índice:</b> ' + escapeHtml(depPrimaryText) + '</div>' +
                '<div><b>Anclas de identidad:</b> ' + escapeHtml(depIdentity.length ? depIdentity.join(' · ') : '—') + '</div>' +
                '<div><b>Vocabulario estructurado:</b> ' + escapeHtml(depVocabulary.length ? depVocabulary.join(' · ') : '—') + '</div>' +
                '<div><b>Acciones:</b> ' + escapeHtml(depActions.length ? depActions.join(' · ') : '—') + '</div>' +
                '<div><b>Contexto secundario:</b> ' + escapeHtml(depContext.length ? depContext.join(' · ') : '—') + '</div>' +
                '<div><b>Busca en campos:</b> ' + escapeHtml(depFields.length ? depFields.join(' · ') : '—') + '</div>' +
                '<div><b>Rutas semánticas activas:</b> ' + escapeHtml(depRoutesText) + '</div>' +
                '<div><b>Estrategia:</b> ' + escapeHtml(String(dep.strategy || '—')) + ' · <b>Índice completo:</b> ' + escapeHtml(String(dep.extended_search || '—')) + '</div>' +
                '<div><b>Base léxica:</b> título ' + escapeHtml(String(dep.lexical_identity_title_rows || 0)) + ' · categoría ' + escapeHtml(String(dep.lexical_identity_category_rows || 0)) + ' · mostrador ' + escapeHtml(String(dep.lexical_identity_rows || 0)) + '</div>' +
                '<div><b>Fuente del mostrador:</b> ' + escapeHtml(String(dep.presentation_source || 'ranking')) + '</div>' +
                '<div><b>Catálogo vivo:</b> IDs ' + escapeHtml(String(dep.live_catalog_ids || 0)) + ' · indexados ' + escapeHtml(String(dep.live_catalog_rows || 0)) + ' · reparados ' + escapeHtml(String(dep.live_catalog_reindexed || 0)) + ' · fuente ' + escapeHtml(String(dep.live_catalog_source || 'none')) + '</div>' +
                '<div><b>Validación técnica:</b> antes ' + escapeHtml(String(dep.matched_rows_before_live_validation || 0)) + ' · después ' + escapeHtml(String(dep.matched_rows || 0)) + '</div>' +
                '<div><b>Candidatos:</b> primarios ' + escapeHtml(String(dep.primary_rows || 0)) + ' · recuperados ' + escapeHtml(String(dep.candidate_rows || 0)) + ' · puntuados ' + escapeHtml(String(dep.matched_rows || 0)) + '</div>' +
                '<div><b>Descubrimiento:</b> ' + escapeHtml(String(dep.discovery_source || '—')) + ' · candidatos ' + escapeHtml(String(dep.discovery_candidates || 0)) + ' · categorías ' + escapeHtml(String(dep.discovery_categories || 0)) + '</div>' +
                '<div><b>Filas SIN FILTRAR pedidas:</b> ' + escapeHtml(String(dep.unfiltered_primary_count || depUnfilteredRows.length || 0)) + '</div>' +
                depUnfilteredHtml +
                depMatchesHtml +
                '</div>';
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
            const discoveryHtml = renderSearchDiscovery(data && data.discovery ? data.discovery : null);
            if (!results.length) {
                const hasClarification = Boolean(
                    data && data.clarification && data.clarification.should_ask &&
                    Array.isArray(data.clarification.options) && data.clarification.options.length >= 2
                );
                const helpHtml = elements.help ? '<div class="seo-dependiente__empty-help">' + assistantAvatarHtml('seo-dependiente__assistant-avatar--message') + '<button type="button" class="seo-dependiente__empty-action" data-dependiente-help-open>Pedir ayuda con esta búsqueda</button><small>Enviaremos el recorrido de Dependiente para que no tengas que empezar de cero.</small></div>' : '';

                // Nunca mostramos paginación o un hueco vacío si no existen
                // tarjetas publicables. Si hay navegación visual real del
                // Dependiente, esa navegación ocupa la zona principal.
                elements.results.innerHTML = discoveryHtml || (hasClarification
                    ? ''
                    : '<div class="seo-dependiente__empty is-awaiting-clarification"><strong>Vamos a afinar la búsqueda</strong><span>Dependiente está revisando las opciones más relacionadas. Puedes concretar la consulta o usar los filtros si quieres.</span>' + helpHtml + '</div>');
                return;
            }

            const heading = '<div class="seo-dependiente__results-heading"><small>Resultados de Dependiente</small><strong>Productos que mejor encajan</strong></div>';
            elements.results.innerHTML = discoveryHtml + heading + results.map(function (product, index) {
                return renderProductCard(product, index + 1);
            }).join('');
        }

        function renderSearchDiscovery(discovery) {
            if (!discovery) return '';
            const categories = Array.isArray(discovery.categories) ? discovery.categories.slice(0, 12) : [];
            const quick = Array.isArray(discovery.quick_filters) ? discovery.quick_filters.slice(0, 6) : [];
            const products = Array.isArray(discovery.products) ? discovery.products.slice(0, 12) : [];
            if (!categories.length && !quick.length && !products.length) return '';

            const chips = quick.map(function (card) {
                return '<button type="button" class="seo-dependiente__discovery-chip" data-dependiente-discovery-filter="' + escapeAttr(JSON.stringify(card.filter || {})) + '" data-dependiente-discovery-label="' + escapeAttr(card.label || '') + '">' + escapeHtml(card.label || '') + '<small>' + numberFormat(card.count || 0) + '</small></button>';
            }).join('');

            const cards = categories.map(function (card) {
                const imageUrl = String(card.image || '');
                const isPlaceholder = !imageUrl || /woocommerce-placeholder|\/placeholder\./i.test(imageUrl);
                const hasUsefulImage = !isPlaceholder && card.image_kind !== 'none';
                const imageClass = hasUsefulImage && card.image_kind === 'logo' ? ' seo-dependiente__visual-card--logo' : (hasUsefulImage ? '' : ' seo-dependiente__visual-card--text-only');
                const media = hasUsefulImage ? '<img src="' + escapeAttr(imageUrl) + '" alt="" loading="lazy" decoding="async">' : '';
                const inner = media +
                    '<span class="seo-dependiente__visual-card-arrow" aria-hidden="true">↗</span>' +
                    '<span class="seo-dependiente__visual-card-content"><strong>' + escapeHtml(card.label || '') + '</strong><small>' + numberFormat(card.count || 0) + ' opciones</small></span>';

                if (card.url) {
                    return '<a class="seo-dependiente__visual-card seo-dependiente__search-visual-card' + imageClass + '" href="' + escapeAttr(card.url) + '" target="_blank" rel="noopener noreferrer">' + inner + '</a>';
                }

                return '<button type="button" class="seo-dependiente__visual-card seo-dependiente__search-visual-card' + imageClass + '" data-dependiente-discovery-filter="' + escapeAttr(JSON.stringify(card.filter || {})) + '" data-dependiente-discovery-label="' + escapeAttr(card.label || '') + '">' + inner + '</button>';
            }).join('');

            const lexicalCandidates = String(discovery.source || '') === 'lexical_candidates';
            const eyebrow = lexicalCandidates ? 'Según los candidatos encontrados por Dependiente' : 'Según los resultados de Dependiente';
            const heading = lexicalCandidates ? 'Elige una categoría o abre un producto' : 'Elige una opción para afinar';
            const candidateNote = lexicalCandidates && Number(discovery.candidate_count || 0) > 0
                ? '<p class="seo-dependiente__discovery-note">Dependiente ha encontrado ' + numberFormat(discovery.candidate_count || 0) + ' candidatos en su índice. Las categorías y productos de abajo salen directamente de esa primera pasada.</p>'
                : '';

            const productHeading = products.length
                ? '<div class="seo-dependiente__results-heading" style="margin-top:20px"><small>Productos encontrados por Dependiente</small><strong>' + (lexicalCandidates ? 'Primera pasada sin filtrar' : 'Productos relacionados') + '</strong></div>'
                : '';
            const productGrid = products.length
                ? '<div class="seo-dependiente__discovery-product-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:12px">' + products.map(function (product, index) {
                    return renderProductCard(product, index + 1);
                }).join('') + '</div>'
                : '';

            return '<section class="seo-dependiente__result-discovery" aria-label="Opciones para afinar la búsqueda">' +
                '<div class="seo-dependiente__result-discovery-head"><small>' + escapeHtml(eyebrow) + '</small><strong>' + escapeHtml(heading) + '</strong></div>' +
                candidateNote +
                (chips ? '<div class="seo-dependiente__discovery-chips">' + chips + '</div>' : '') +
                (cards ? '<div class="seo-dependiente__visual-menu seo-dependiente__search-visual-menu">' + cards + '</div>' : '') +
                productHeading + productGrid +
                '</section>';
        }

        function zeroResultAlternatives(data) {
            // Ya no convertimos un estado débil en navegación por categorías.
            // El Dependiente mantiene la búsqueda normal y el Intérprete solo
            // añade contexto lingüístico mediante sus preguntas.
            return [];
        }

        function renderProductCard(product, position) {
            const selected = state.compare.has(Number(product.id));
            const categories = (product.categories || []).join(' · ');
            const tier = product.search_tier === 'extended' ? 'Relacionado' : 'Coincidencia directa';
            const kicker = [
                '<span class="seo-dependiente__search-tier ' + (product.search_tier === 'extended' ? 'is-extended' : 'is-direct') + '">' + tier + '</span>',
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
                '<a class="seo-dependiente__product-image" data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '" target="_blank" rel="noopener noreferrer"><img src="' + escapeAttr(product.image || config.placeholderImage || '') + '" alt="' + escapeAttr(product.title) + '" loading="lazy"></a>' +
                '<button type="button" class="seo-dependiente__compare-toggle" data-compare-id="' + Number(product.id) + '" aria-pressed="' + (selected ? 'true' : 'false') + '">' + (selected ? 'Seleccionado' : 'Comparar') + '</button>' +
                '<div class="seo-dependiente__product-body">' +
                '<div class="seo-dependiente__product-kicker">' + kicker + '</div>' +
                '<h2 class="seo-dependiente__product-title"><a data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(product.title) + '</a></h2>' +
                (reasons ? '<div class="seo-dependiente__match-reasons">' + reasons + '</div>' : '') +
                (product.excerpt ? '<p class="seo-dependiente__excerpt">' + escapeHtml(product.excerpt) + '</p>' : '') +
                (specs ? '<div class="seo-dependiente__specs">' + specs + '</div>' : '') +
                '<div class="seo-dependiente__product-foot"><div class="seo-dependiente__price">' + (product.price_html || '') + '</div><a class="seo-dependiente__card-button" data-dependiente-product-link data-product-id="' + Number(product.id) + '" data-position="' + Number(position || 0) + '" href="' + escapeAttr(product.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml((config.labels && config.labels.viewProduct) || 'Ver producto') + '</a></div>' +
                '</div></article>';
        }

        function clearAmazon() {
            if (!elements.amazon) return;
            elements.amazon.hidden = true;
            elements.amazon.innerHTML = '';
        }

        async function loadAmazonFallback(fallback) {
            if (!elements.amazon) return;
            const requestId = ++state.amazonRequestId;
            clearAmazon();
            if (!fallback) return;

            if (!fallback.should_load) {
                const status = String(fallback.status || '');
                if (status === 'partner_tag_missing') {
                    renderAmazonStatus(
                        'Amazon Afiliados está pendiente de configurar.',
                        'Falta el Partner Tag de amazon.es. No hace falta Creators API: guarda únicamente el Partner Tag en Conexiones → Amazon Afiliados.'
                    );
                } else if (status && status !== 'empty_query' && status !== 'query_unusable' && status !== 'inactive') {
                    renderAmazonStatus(
                        'No he podido preparar Amazon para esta búsqueda.',
                        'El catálogo y las guías siguen disponibles. Estado: ' + status + '.'
                    );
                }
                return;
            }

            if (!fallback.query || !fallback.token || !fallback.bucket) {
                renderAmazonStatus(
                    'No he podido preparar Amazon para esta búsqueda.',
                    'La configuración afiliada existe, pero faltan datos internos de la solicitud.'
                );
                return;
            }

            elements.amazon.hidden = false;
            elements.amazon.innerHTML = '<div class="seo-dependiente__amazon-loading"><strong>Preparando opciones de Amazon…</strong><span>Las añadimos después de nuestro catálogo y nuestras guías.</span></div>';

            try {
                const data = await api('amazon-search', {
                    method: 'POST',
                    body: {
                        q: String(fallback.query || ''),
                        token: String(fallback.token || ''),
                        bucket: Number(fallback.bucket || 0)
                    }
                });
                if (requestId !== state.amazonRequestId) return;
                renderAmazon(data || {}, fallback);
            } catch (error) {
                // No rompemos la busqueda principal, pero tampoco ocultamos que el
                // proveedor externo fue consultado y no pudo responder.
                if (requestId === state.amazonRequestId) {
                    renderAmazonStatus('No he podido preparar los enlaces de Amazon.', 'Nuestro catálogo y nuestras guías siguen disponibles.');
                }
            }
        }

        function renderAmazonStatus(title, text) {
            if (!elements.amazon) return;
            elements.amazon.hidden = false;
            elements.amazon.innerHTML = '<div class="seo-dependiente__amazon-loading"><strong>' + escapeHtml(title || '') + '</strong><span>' + escapeHtml(text || '') + '</span></div>';
        }

        function renderAmazon(response, fallback) {
            if (!elements.amazon) return;
            const items = response && Array.isArray(response.items) ? response.items : [];
            const mode = response && response.mode ? String(response.mode) : 'affiliate';
            if (!items.length) {
                renderAmazonStatus('No hay opciones de Amazon para esta búsqueda.', 'Nuestro catálogo y nuestras guías siguen disponibles.');
                return;
            }

            const affiliateMode = mode === 'affiliate' || items.some(function (item) { return item && item.type === 'search'; });
            const reasonText = affiliateMode
                ? 'Estas son búsquedas afiliadas relacionadas. Al abrirlas verás en Amazon los productos, precios y disponibilidad actualizados.'
                : 'Como complemento a los productos y contenidos de nuestra web, también hemos consultado Amazon.';
            const contextImages = fallback && Array.isArray(fallback.context_images)
                ? fallback.context_images.filter(Boolean)
                : [];
            const cards = affiliateMode
                ? items.map(function (item, index) { return renderAmazonSearchCard(item, index, contextImages); }).join('')
                : items.map(renderAmazonCard).join('');
            const title = affiliateMode ? 'Más opciones en Amazon' : 'Productos relacionados en Amazon';
            const eyebrow = affiliateMode ? 'Búsquedas afiliadas' : 'Catálogo externo';

            elements.amazon.innerHTML = '<div class="seo-dependiente__amazon-head">' +
                '<div><span class="seo-dependiente__amazon-eyebrow">' + escapeHtml(eyebrow) + '</span><h2>' + escapeHtml(title) + '</h2><p>' + escapeHtml(reasonText) + '</p></div>' +
                '<span class="seo-dependiente__amazon-badge">Amazon</span>' +
                '</div>' +
                '<div class="seo-dependiente__amazon-grid">' + cards + '</div>' +
                '<p class="seo-dependiente__amazon-disclosure">Como Afiliado de Amazon, podemos obtener ingresos por compras adscritas realizadas desde estos enlaces. En el modo sin API no mostramos precios ni disponibilidad: se consultan directamente en Amazon.</p>';
            bindAmazonImageFallbacks(elements.amazon, contextImages);
            elements.amazon.hidden = false;
        }

        function bindAmazonImageFallbacks(root, contextImages) {
            if (!root) return;

            const fallbackImage = String(config.placeholderImage || '');
            const cleanContextImages = Array.isArray(contextImages)
                ? contextImages.map(function (url) { return String(url || '').trim(); }).filter(Boolean)
                : [];

            /*
             * Las tarjetas sin API se pintan primero con un placeholder limpio.
             * Solo insertamos una imagen despues de comprobar que el navegador puede
             * cargarla. Asi evitamos el icono de imagen rota y podemos saltar a la
             * siguiente foto de proveedor si una URL remota ha caducado.
             */
            root.querySelectorAll('.seo-dependiente__amazon-search-image').forEach(function (image) {
                const startIndex = Math.max(0, Number(image.dataset.amazonImageIndex || 0));
                const candidates = [];

                if (cleanContextImages.length) {
                    const offset = startIndex % cleanContextImages.length;
                    cleanContextImages.slice(offset).concat(cleanContextImages.slice(0, offset)).forEach(function (url) {
                        if (url && candidates.indexOf(url) === -1) candidates.push(url);
                    });
                }

                if (fallbackImage && candidates.indexOf(fallbackImage) === -1) {
                    candidates.push(fallbackImage);
                }

                const visual = image.closest('.seo-dependiente__amazon-search-visual');
                const placeholder = visual ? visual.querySelector('.seo-dependiente__amazon-search-placeholder') : null;
                const note = visual ? visual.querySelector('.seo-dependiente__amazon-media-note') : null;

                const tryCandidate = function (candidateIndex) {
                    if (candidateIndex >= candidates.length) {
                        image.hidden = true;
                        image.removeAttribute('src');
                        if (placeholder) placeholder.hidden = false;
                        if (note) note.hidden = true;
                        return;
                    }

                    const candidate = candidates[candidateIndex];
                    const probe = new Image();

                    probe.onload = function () {
                        image.src = candidate;
                        image.hidden = false;
                        if (placeholder) placeholder.hidden = true;

                        const isFallback = fallbackImage && candidate === fallbackImage;
                        image.classList.toggle('seo-dependiente__amazon-search-image--fallback', Boolean(isFallback));
                        if (note) {
                            note.textContent = isFallback ? 'Imagen de referencia' : 'Imagen orientativa relacionada';
                            note.hidden = false;
                        }
                    };

                    probe.onerror = function () {
                        tryCandidate(candidateIndex + 1);
                    };

                    probe.src = candidate;
                };

                tryCandidate(0);
            });

            /*
             * Fichas enriquecidas de Creators: si alguna imagen real fallase,
             * intentamos el fallback corporativo y, si tambien falla, la ocultamos.
             */
            root.querySelectorAll('.seo-dependiente__amazon-image img').forEach(function (image) {
                image.addEventListener('error', function () {
                    if (fallbackImage && image.dataset.fallbackApplied !== '1') {
                        image.dataset.fallbackApplied = '1';
                        image.src = fallbackImage;
                        return;
                    }
                    image.style.visibility = 'hidden';
                });
            });
        }

        function renderAmazonSearchCard(item, index, contextImages) {
            const query = item.query || item.title || '';
            const placeholder = '<span class="seo-dependiente__amazon-search-placeholder">' +
                '<span class="seo-dependiente__amazon-search-mark">amazon</span>' +
                '<span class="seo-dependiente__amazon-search-query">' + escapeHtml(query) + '</span>' +
                '</span>';
            const visual = placeholder +
                '<img class="seo-dependiente__amazon-search-image" data-amazon-image-index="' + Number(index || 0) + '" alt="" loading="lazy" decoding="async" hidden>' +
                '<span class="seo-dependiente__amazon-source-badge">Amazon</span>' +
                '<span class="seo-dependiente__amazon-media-note" hidden></span>';

            return '<article class="seo-dependiente__amazon-card seo-dependiente__amazon-card--search">' +
                '<a class="seo-dependiente__amazon-search-visual" href="' + escapeAttr(item.url || '#') + '" target="_blank" rel="sponsored nofollow noopener" aria-label="' + escapeAttr('Buscar ' + query + ' en Amazon') + '">' +
                    visual +
                '</a>' +
                '<div class="seo-dependiente__amazon-body">' +
                    '<div class="seo-dependiente__amazon-kicker">Enlace de afiliado · Búsqueda en Amazon</div>' +
                    '<h3><a href="' + escapeAttr(item.url || '#') + '" target="_blank" rel="sponsored nofollow noopener">' + escapeHtml(item.title || query) + '</a></h3>' +
                    (item.description ? '<p class="seo-dependiente__amazon-search-description">' + escapeHtml(item.description) + '</p>' : '') +
                    '<div class="seo-dependiente__amazon-foot"><span></span><a href="' + escapeAttr(item.url || '#') + '" target="_blank" rel="sponsored nofollow noopener">Ver opciones en Amazon ↗</a></div>' +
                '</div>' +
            '</article>';
        }

        function renderAmazonCard(product) {
            const image = product.image || config.placeholderImage || '';
            const features = (product.features || []).slice(0, 2).map(function (feature) {
                return '<li>' + escapeHtml(feature) + '</li>';
            }).join('');
            const kicker = ['Enlace pagado', product.brand ? escapeHtml(product.brand) : '', product.asin ? 'ASIN ' + escapeHtml(product.asin) : ''].filter(Boolean).join(' · ');

            return '<article class="seo-dependiente__amazon-card">' +
                '<a class="seo-dependiente__amazon-image" href="' + escapeAttr(product.url || '#') + '" target="_blank" rel="sponsored nofollow noopener"><img src="' + escapeAttr(image) + '" alt="' + escapeAttr(product.title || '') + '" loading="lazy" decoding="async"></a>' +
                '<div class="seo-dependiente__amazon-body">' +
                    (kicker ? '<div class="seo-dependiente__amazon-kicker">' + kicker + '</div>' : '') +
                    '<h3><a href="' + escapeAttr(product.url || '#') + '" target="_blank" rel="sponsored nofollow noopener">' + escapeHtml(product.title || '') + '</a></h3>' +
                    (features ? '<ul class="seo-dependiente__amazon-features">' + features + '</ul>' : '') +
                    '<div class="seo-dependiente__amazon-foot"><strong>' + escapeHtml(product.price || 'Consultar en Amazon') + '</strong><a href="' + escapeAttr(product.url || '#') + '" target="_blank" rel="sponsored nofollow noopener">Ver en Amazon ↗</a></div>' +
                '</div>' +
            '</article>';
        }

        function renderCandidateProducts(products) {
            let container = root.querySelector('[data-dependiente-candidate-products]');
            if (!container) {
                container = document.createElement('section');
                container.setAttribute('data-dependiente-candidate-products', '');
                container.className = 'seo-dependiente__candidate-products';
                // Debe quedar antes de Guías y soluciones. Si existe el carril
                // editorial, insertamos el bloque justo delante.
                if (elements.related && elements.related.parentNode) {
                    elements.related.parentNode.insertBefore(container, elements.related);
                } else if (elements.workspace) {
                    elements.workspace.appendChild(container);
                }
            }

            if (!Array.isArray(products) || !products.length) {
                container.hidden = true;
                container.innerHTML = '';
                return;
            }

            const cards = products.slice(0, 12).map(function (product, index) {
                return renderProductCard(product, index + 1);
            }).join('');

            container.innerHTML = '<div class="seo-dependiente__results-heading" style="margin:0 0 14px"><small>Productos encontrados por Dependiente</small><strong>Mejor puntuación entre los candidatos</strong></div>' +
                '<div class="seo-dependiente__candidate-product-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">' + cards + '</div>';
            container.hidden = false;
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
            const helpText = 'Contenido de nuestras guías y soluciones relacionado directamente con tu búsqueda o con los productos encontrados.';
            const total = items.length;

            elements.related.innerHTML = '<div class="seo-dependiente__related-inner">' +
                '<div class="seo-dependiente__related-summary">' +
                    '<span class="seo-dependiente__related-summary-copy"><small>Información de nuestra web</small><strong>Guías y soluciones <em>(' + total + ')</em></strong></span>' +
                '</div>' +
                '<div class="seo-dependiente__related-panel">' +
                    '<p class="seo-dependiente__related-help">' + escapeHtml(helpText) + '</p>' +
                    '<div class="seo-dependiente__related-list">' + cards + '</div>' + more +
                '</div>' +
            '</div>';
            elements.related.hidden = false;
        }

        function renderRelatedCard(item) {
            const image = item.image ? '<span class="seo-dependiente__related-image"><img src="' + escapeAttr(item.image) + '" alt="" loading="lazy" decoding="async"></span>' : '<span class="seo-dependiente__related-image seo-dependiente__related-image--empty" aria-hidden="true"></span>';
            const url = escapeAttr(item.url || '#');
            return '<a class="seo-dependiente__related-card" href="' + url + '">' + image +
                '<span class="seo-dependiente__related-body"><span class="seo-dependiente__related-type">' + escapeHtml(item.type_label || 'Guía') + '</span>' +
                '<strong class="seo-dependiente__related-title">' + escapeHtml(item.title || '') + '</strong>' +
                (item.excerpt ? '<span class="seo-dependiente__related-excerpt">' + escapeHtml(item.excerpt) + '</span>' : '') +
                '<span class="seo-dependiente__related-link">Leer →</span></span></a>';
        }

        function renderPagination(page, pages, visibleResults) {
            if (pages <= 1 || Number(visibleResults || 0) <= 0) {
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
                return '<th><div class="seo-dependiente__comparison-product"><img src="' + escapeAttr(product.image || config.placeholderImage || '') + '" alt=""><a href="' + escapeAttr(product.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(product.title) + '</a></div></th>';
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
            url.searchParams.delete('dep_role');
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
