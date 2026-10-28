/* global URL, URLSearchParams */
/**
 * Automatic, Spanish-only Google Trends comparisons.
 * Sequential iframe navigation with five browser load speeds. No Google
 * JSON scraping and no claim of a numeric growth analysis.
 */
(function () {
    'use strict';
    const root = document.getElementById('tgb-hierarchy');
    if (!root) { return; }

    const period = root.querySelector('#tgb-h-period');
    const speed = root.querySelector('#tgb-h-speed');
    const speedLabel = root.querySelector('#tgb-h-speed-label');
    const progress = root.querySelector('#tgb-h-progress');
    const status = root.querySelector('#tgb-h-state');
    const log = root.querySelector('#tgb-h-log');
    const start = root.querySelector('#tgb-h-start');
    const pause = root.querySelector('#tgb-h-pause');
    const resume = root.querySelector('#tgb-h-resume');
    const stop = root.querySelector('#tgb-h-stop');
    const detailChart = root.querySelector('#tgb-h-chart');
    const detailTitle = root.querySelector('#tgb-h-chart-title');
    const detailLink = root.querySelector('#tgb-h-explore');
    const cards = Array.from(root.querySelectorAll('.tgb-h-compare-card'));
    const delays = {1: 15000, 2: 8000, 3: 4000, 4: 2000, 5: 1000};

    let queue = [];
    let completed = 0;
    let total = 0;
    let running = false;
    let paused = false;
    let busy = false;
    let waitTimer = null;
    let activeTimer = null;
    let activeFrame = null;
    let activeCard = null;
    let generation = 0;

    function periodValue() {
        const valid = ['today 1-m', 'today 3-m', 'today 12-m', 'today 5-y'];
        return valid.includes(period.value) ? period.value : 'today 12-m';
    }

    function comparisonUrls(terms) {
        const periodString = periodValue();
        const config = terms.map(function (term) {
            return {keyword: term, geo: 'ES', time: periodString};
        });
        const explore = new URL('https://trends.google.com/trends/explore');
        explore.searchParams.set('geo', 'ES');
        explore.searchParams.set('date', periodString);
        explore.searchParams.set('q', terms.join(','));

        const embed = new URL('https://trends.google.com/trends/embed/explore/TIMESERIES');
        embed.searchParams.set('req', JSON.stringify({
            comparisonItem: config, category: 0, property: ''
        }));
        embed.searchParams.set('tz', String(new Date().getTimezoneOffset()));
        embed.searchParams.set('hl', 'es');
        const eq = new URLSearchParams();
        eq.set('geo', 'ES');
        eq.set('date', periodString);
        eq.set('q', terms.join(','));
        embed.searchParams.set('eq', eq.toString());
        return {explore: explore.toString(), embed: embed.toString()};
    }

    function appendLog(message) {
        const entry = document.createElement('li');
        entry.textContent = new Date().toLocaleTimeString('es-ES') + ' · ' + message;
        log.prepend(entry);
        while (log.children.length > 60) { log.lastElementChild.remove(); }
    }

    function controls() {
        start.disabled = running;
        pause.disabled = !running || paused;
        resume.disabled = !running || !paused;
        stop.disabled = !running;
        period.disabled = false; // A period change starts a fresh report.
        root.querySelectorAll('.tgb-h-check, .tgb-h-cat-check').forEach(function (button) {
            button.disabled = running;
        });
    }

    function cancelInFlight() {
        generation += 1;
        if (waitTimer !== null) { clearTimeout(waitTimer); waitTimer = null; }
        if (activeTimer !== null) { clearTimeout(activeTimer); activeTimer = null; }
        if (activeFrame) {
            activeFrame.onload = null;
            activeFrame.onerror = null;
            activeFrame.removeAttribute('src');
            activeFrame.remove();
            activeFrame = null;
        }
        if (activeCard) {
            const label = activeCard.querySelector('.tgb-h-compare-state');
            if (label) { label.textContent = 'Interrumpido'; }
            activeCard = null;
        }
        busy = false;
    }

    function cardTerms(card) {
        try {
            const parsed = JSON.parse(card.dataset.terms || '[]');
            return Array.isArray(parsed)
                ? parsed.filter(function (term) { return typeof term === 'string' && term.trim().length; }).slice(0, 5)
                : [];
        } catch (error) {
            return [];
        }
    }

    function loadCard(card) {
        const terms = cardTerms(card);
        const target = card.querySelector('.tgb-h-compare-chart');
        const label = card.querySelector('.tgb-h-compare-state');
        const link = card.querySelector('.tgb-h-compare-link');
        if (!target || !label || !terms.length) { return; }

        const urls = comparisonUrls(terms);
        link.href = urls.explore;
        const iframe = document.createElement('iframe');
        iframe.title = 'Google Trends España: ' + terms.join(', ');
        iframe.width = '100%';
        iframe.height = '440';
        iframe.loading = 'eager';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation');

        target.replaceChildren(iframe);
        label.textContent = 'Solicitando gráfico a Google…';
        activeCard = card;
        activeFrame = iframe;
        const token = generation;
        let settled = false;

        function settle(outcome) {
            if (settled || token !== generation) { return; }
            settled = true;
            if (activeTimer !== null) { clearTimeout(activeTimer); activeTimer = null; }
            iframe.onload = null;
            iframe.onerror = null;
            activeFrame = null;
            activeCard = null;
            busy = false;
            completed += 1;
            progress.value = Math.round(100 * completed / Math.max(1, total));
            label.textContent = outcome === 'navegado'
                ? 'Gráfico solicitado; comprueba los datos en Google'
                : 'Google no ha confirmado la carga; utiliza el enlace oficial';
            appendLog('Gráfico ' + completed + '/' + total + ' · ' + outcome + ' · ' + terms.join(' / '));
            status.textContent = 'Gráficos solicitados: ' + completed + '/' + total;
            if (running && !paused) { scheduleNext(); }
        }

        iframe.onload = function () {
            // The event does not verify that the cross-origin chart has data.
            settle('navegado');
        };
        iframe.onerror = function () { settle('sin confirmar'); };
        activeTimer = setTimeout(function () { settle('sin confirmar'); }, 18000);
        iframe.src = urls.embed;
    }

    function scheduleNext() {
        if (!running || paused || busy) { return; }
        if (!queue.length) {
            running = false;
            controls();
            status.textContent = 'Informe visual solicitado: ' + completed + ' gráficos de ' + total + '. Verifica sus contenidos en Google.';
            appendLog('Fin del informe automático. No se han calculado volúmenes ni crecimientos.');
            return;
        }
        if (waitTimer !== null) { clearTimeout(waitTimer); }
        waitTimer = setTimeout(function () { waitTimer = null; next(); },
            delays[Number(speed.value)] || delays[2]);
    }

    function next() {
        if (!running || paused || busy) { return; }
        if (!queue.length) {
            scheduleNext();
            return;
        }
        busy = true;
        const card = queue.shift();
        const heading = card.querySelector('h4');
        status.textContent = 'Cargando ' + (completed + 1) + '/' + total + ': ' + (heading ? heading.textContent : 'Comparación');
        loadCard(card);
    }

    function startAutomatic() {
        // Restarting a report must not leave timers or stale embedded charts.
        cancelInFlight();
        running = false;
        paused = false;
        cards.forEach(function (card) {
            const graph = card.querySelector('.tgb-h-compare-chart');
            if (graph) { graph.replaceChildren(); }
            const terms = cardTerms(card);
            const link = card.querySelector('.tgb-h-compare-link');
            if (link && terms.length) { link.href = comparisonUrls(terms).explore; }
            const state = card.querySelector('.tgb-h-compare-state');
            if (state) { state.textContent = 'En cola'; }
        });
        const single = detailChart.querySelector('iframe');
        if (single) { single.remove(); }
        queue = cards.filter(function (card) { return cardTerms(card).length > 0; });
        completed = 0;
        total = queue.length;
        progress.value = 0;
        if (!total) {
            status.textContent = 'Sin clústeres ni hubs primarios españoles para comparar.';
            controls();
            return;
        }
        running = true;
        controls();
        appendLog('Inicio automático: ' + total + ' gráficos · España · ' + period.options[period.selectedIndex].textContent + '.');
        // First graph is immediate; following graphs use the selected speed.
        next();
    }

    start.addEventListener('click', startAutomatic);
    period.addEventListener('change', startAutomatic);
    speed.addEventListener('input', function () { speedLabel.textContent = speed.value; });
    pause.addEventListener('click', function () {
        if (!running || paused) { return; }
        paused = true;
        if (waitTimer !== null) { clearTimeout(waitTimer); waitTimer = null; }
        status.textContent = 'Pausado (' + completed + '/' + total + '); puede finalizar el gráfico en curso.';
        appendLog('Pausa solicitada.');
        controls();
    });
    resume.addEventListener('click', function () {
        if (!running || !paused) { return; }
        paused = false;
        appendLog('Reanudado.');
        controls();
        if (!busy) { scheduleNext(); }
    });
    stop.addEventListener('click', function () {
        if (!running) { return; }
        cancelInFlight();
        running = false;
        paused = false;
        queue = [];
        status.textContent = 'Detenido: ' + completed + '/' + total + ' gráficos solicitados.';
        appendLog('Proceso detenido.');
        controls();
    });

    // Secondary manual exploration preserves the previous hierarchy.
    function singleQuery(name) {
        if (running || !name) { return; }
        const urls = comparisonUrls([name]);
        detailTitle.textContent = 'Interés de búsqueda: ' + name;
        detailLink.href = urls.explore;
        detailLink.hidden = false;
        detailChart.replaceChildren();
        const iframe = document.createElement('iframe');
        iframe.title = 'Google Trends España: ' + name;
        iframe.width = '100%';
        iframe.height = '440';
        iframe.loading = 'lazy';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation');
        detailChart.appendChild(iframe);
        iframe.src = urls.embed;
        appendLog('Exploración manual: ' + name);
    }

    root.querySelectorAll('.tgb-h-check').forEach(function (button) {
        button.addEventListener('click', function () {
            if (running) { return; }
            const name = button.dataset.query || '';
            if (!name) { return; }
            singleQuery(name);
            const node = button.closest('.tgb-h-node');
            const state = node ? node.querySelector('.tgb-h-status') : null;
            if (state) { state.textContent = 'Gráfico solicitado'; }
        });
    });
    root.querySelectorAll('.tgb-h-cat-check').forEach(function (button) {
        button.addEventListener('click', function () {
            if (running) { return; }
            const select = button.parentElement.querySelector('.tgb-h-cat-select');
            const option = select && select.selectedOptions[0];
            if (!option || !option.value) {
                status.textContent = 'Selecciona una categoría antes de consultar.';
                return;
            }
            singleQuery(option.dataset.query || option.textContent);
        });
    });

    // The key behavior: no button press is required to obtain the charts.
    controls();
    startAutomatic();
}());
