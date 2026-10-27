/* global URL, URLSearchParams */
/**
 * Google Trends Explore embeds, triggered only by the administrator clicking
 * "Buscar tendencias". No WordPress backend calls Google and nothing loads
 * when merely opening the tab.
 *
 * Google owns these chart URLs and can limit or change their availability.
 */
(function () {
    'use strict';

    const form = document.getElementById('tgb-trends-search-form');
    const results = document.getElementById('tgb-trends-results');
    const link = document.getElementById('tgb-trends-open-google');
    const errorBox = document.getElementById('tgb-trends-validation');
    const emptyBox = document.getElementById('tgb-trends-empty');
    if (!form || !results || !link || !errorBox) {
        return;
    }

    const textField = form.querySelector('[name="trends_terms"]');
    const geoField = form.querySelector('[name="explore_geo"]');
    const periodField = form.querySelector('[name="explore_period"]');
    const allowedPeriods = ['now 7-d', 'today 1-m', 'today 3-m', 'today 12-m', 'today 5-y'];
    const exploreBase = 'https://trends.google.com/trends/explore';
    const embedBase = 'https://trends.google.com/trends/embed/explore/';

    function parseTerms(raw) {
        if (raw.length > 1500) {
            throw new Error('La lista es demasiado larga.');
        }
        const terms = [];
        const dedup = new Set();
        raw.split(/[\r\n;,]+/).forEach(function (part) {
            const term = part.trim();
            if (!term) {
                return;
            }
            if (Array.from(term).length > 120) {
                throw new Error('Cada frase puede contener un máximo de 120 caracteres.');
            }
            const key = term.toLocaleLowerCase('es');
            if (!dedup.has(key)) {
                dedup.add(key);
                terms.push(term);
            }
        });
        if (!terms.length) {
            throw new Error('Escribe al menos una palabra o frase.');
        }
        if (terms.length > 5) {
            throw new Error('Google Trends permite comparar un máximo de cinco frases en esta consulta.');
        }
        return terms;
    }

    function selection() {
        const terms = parseTerms(textField.value);
        const geo = 'ES'; // Spain only, regardless of field changes.
        const period = allowedPeriods.includes(periodField.value) ? periodField.value : 'today 12-m';
        return {terms: terms, geo: geo, period: period};
    }

    function exploreUrl(config) {
        const url = new URL(exploreBase);
        url.searchParams.set('q', config.terms.join(','));
        url.searchParams.set('date', config.period);
        if (config.geo) {
            url.searchParams.set('geo', config.geo);
        }
        return url.toString();
    }

    function embedUrl(kind, config) {
        // Mirrors the query parameters in Google's own chart embed URLs.
        // Never calls Trends' internal JSON API, nor impersonates an API client.
        const url = new URL(embedBase + kind);
        const comparisonItem = config.terms.map(function (term) {
            return { keyword: term, geo: config.geo, time: config.period };
        });
        url.searchParams.set('req', JSON.stringify({
            comparisonItem: comparisonItem,
            category: 0,
            property: ''
        }));
        url.searchParams.set('tz', String(new Date().getTimezoneOffset()));
        url.searchParams.set('hl', 'es');

        const eq = new URLSearchParams();
        eq.set('q', config.terms.join(','));
        eq.set('date', config.period);
        if (config.geo) {
            eq.set('geo', config.geo);
        }
        url.searchParams.set('eq', eq.toString());
        return url.toString();
    }

    function node(tagName, className, content) {
        const item = document.createElement(tagName);
        if (className) {
            item.className = className;
        }
        if (content) {
            item.textContent = content;
        }
        return item;
    }

    function graph(title, description, kind, config, height) {
        const section = node('section', 'tgb-trends-chart');
        section.appendChild(node('h3', '', title));
        section.appendChild(node('p', 'description', description));
        const iframe = document.createElement('iframe');
        iframe.title = title + ' - fuente Google Trends';
        iframe.src = embedUrl(kind, config);
        iframe.width = '100%';
        iframe.height = String(height);
        iframe.setAttribute('loading', 'eager');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads allow-top-navigation-by-user-activation');
        section.appendChild(iframe);
        return section;
    }

    function refreshLink() {
        try {
            link.href = exploreUrl(selection());
        } catch (error) {
            link.href = exploreBase;
        }
    }

    function run(event) {
        event.preventDefault(); // Never navigate to Google instead of showing results here.
        errorBox.hidden = true;
        errorBox.textContent = '';
        let config;
        try {
            config = selection();
        } catch (error) {
            results.hidden = true;
            results.replaceChildren();
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            return;
        }

        const country = 'España';
        const period = periodField.selectedOptions.length ? periodField.selectedOptions[0].textContent : config.period;
        const header = node('div', 'tgb-trends-result-heading');
        header.appendChild(node('h3', '', 'Resultados para: ' + config.terms.join(' · ')));
        header.appendChild(node('p', 'description', 'Zona: ' + country + ' · Periodo: ' + period));
        const sourceLink = node('a', 'button', 'Abrir esta consulta en Google Trends ↗');
        sourceLink.href = exploreUrl(config);
        sourceLink.target = '_blank';
        sourceLink.rel = 'noopener noreferrer';
        header.appendChild(sourceLink);

        // Create charts only here, never on page load, tab changes or filters.
        // Three Google-hosted widgets at most per deliberate search.
        const widgets = [
            graph(
                'Interés de búsqueda a lo largo del tiempo',
                'Comparación de las frases seleccionadas; índice relativo 0–100, no número de visitas.',
                'TIMESERIES',
                config,
                440
            ),
            graph(
                'Interés por ubicación: ' + config.terms[0],
                'Distribución geográfica de la primera frase. Muestra países o regiones según el ámbito seleccionado.',
                'GEO_MAP_0',
                config,
                440
            ),
            graph(
                'Consultas relacionadas con: ' + config.terms[0],
                'Búsquedas relacionadas con la primera frase; Google puede limitar este gráfico.',
                'RELATED_QUERIES_0',
                config,
                440
            )
        ];

        const warning = node('p', 'description tgb-trends-embed-notice',
            'Fuente: Google Trends. Los gráficos los proporciona Google directamente. ' +
            'Si Google no permite mostrar alguno o limita las consultas, utiliza el enlace a Google Trends; ' +
            'el plugin no genera datos sustitutivos ni consulta el RSS genérico.');
        results.replaceChildren(header, ...widgets, warning);
        if (emptyBox) { emptyBox.hidden = true; }
        results.hidden = false;
        link.href = sourceLink.href;
        if (typeof results.scrollIntoView === 'function') {
            results.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }

    form.addEventListener('submit', run);
    textField.addEventListener('input', refreshLink);
    geoField.addEventListener('change', refreshLink);
    periodField.addEventListener('change', refreshLink);
    refreshLink();
}());
