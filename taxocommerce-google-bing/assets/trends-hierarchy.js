/* global URL, URLSearchParams */
/**
 * On-demand, sequential Google Trends visual explorer.
 * Browser-side load management only: no server CPU measurements or scraping.
 */
(function () {
    'use strict';
    const root = document.getElementById('tgb-hierarchy');
    if (!root) { return; }

    const chart = root.querySelector('#tgb-h-chart');
    const title = root.querySelector('#tgb-h-chart-title');
    const link = root.querySelector('#tgb-h-explore');
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

    const delays = {1: 15000, 2: 8000, 3: 4000, 4: 2000, 5: 1000};
    let queue = [];
    let done = 0;
    let running = false;
    let paused = false;
    let timer = null;
    let waitTimer = null;
    let generation = 0;
    let active = null;

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
        root.querySelectorAll('.tgb-h-check, .tgb-h-cat-check').forEach(function (button) {
            button.disabled = running;
        });
        period.disabled = running;
    }

    function periodValue() {
        const allowed = ['today 1-m', 'today 3-m', 'today 12-m', 'today 5-y'];
        return allowed.includes(period.value) ? period.value : 'today 12-m';
    }

    function urls(query) {
        const config = {keyword: query, geo: 'ES', time: periodValue()};
        const explore = new URL('https://trends.google.com/trends/explore');
        explore.searchParams.set('geo', 'ES');
        explore.searchParams.set('q', query);
        explore.searchParams.set('date', config.time);

        const embed = new URL('https://trends.google.com/trends/embed/explore/TIMESERIES');
        embed.searchParams.set('req', JSON.stringify({
            comparisonItem: [config],
            category: 0,
            property: ''
        }));
        embed.searchParams.set('tz', String(new Date().getTimezoneOffset()));
        embed.searchParams.set('hl', 'es');
        const eq = new URLSearchParams();
        eq.set('q', query);
        eq.set('geo', 'ES');
        eq.set('date', config.time);
        embed.searchParams.set('eq', eq.toString());
        return {explore: explore.toString(), embed: embed.toString()};
    }

    function cancelActive() {
        if (timer !== null) { clearTimeout(timer); timer = null; }
        if (waitTimer !== null) { clearTimeout(waitTimer); waitTimer = null; }
        generation += 1;
        if (active) {
            active.onload = null;
            active.onerror = null;
            active.removeAttribute('src');
            active.remove();
            active = null;
        }
    }

    function show(query, label, onFinished) {
        cancelActive();
        const token = generation;
        const dest = urls(query);
        title.textContent = 'Interés de búsqueda: ' + label;
        link.href = dest.explore;
        link.hidden = false;
        chart.replaceChildren();

        const iframe = document.createElement('iframe');
        iframe.title = 'Google Trends España: ' + label;
        iframe.width = '100%';
        iframe.height = '440';
        iframe.loading = 'eager';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation');
        active = iframe;
        chart.appendChild(iframe);

        let fired = false;
        function finish(outcome) {
            if (fired || token !== generation) { return; }
            fired = true;
            if (timer !== null) { clearTimeout(timer); timer = null; }
            if (onFinished) { onFinished(outcome); }
        }
        iframe.onload = function () {
            // Cross-origin Google charts cannot be inspected. Load means only
            // the iframe finished navigating, not that it showed trend data.
            finish('solicitado');
        };
        iframe.onerror = function () { finish('sin confirmar'); };
        timer = setTimeout(function () { finish('sin confirmar'); }, 18000);
        iframe.src = dest.embed;
    }

    function badge(button, message) {
        const node = button.closest('.tgb-h-node');
        if (!node) { return; }
        const badgeElement = node.querySelector('.tgb-h-status');
        if (badgeElement) { badgeElement.textContent = message; }
    }

    function finishRun(message) {
        running = false;
        paused = false;
        queue = [];
        controls();
        status.textContent = message;
        appendLog(message);
    }

    function next() {
        if (!running || paused) { return; }
        if (!queue.length) {
            finishRun('Recorrido visual completado: ' + done + ' nodos solicitados. Sin medición numérica de crecimiento.');
            return;
        }
        const item = queue.shift();
        badge(item.button, 'Consultando…');
        status.textContent = 'Solicitando ' + (done + 1) + ' de ' + (done + queue.length + 1) + ' · ' + item.label;
        show(item.query, item.label, function (outcome) {
            done += 1;
            progress.value = Math.round(100 * done / Math.max(1, done + queue.length));
            badge(item.button, outcome === 'solicitado' ? 'Gráfico solicitado' : 'Sin confirmar');
            appendLog(item.kind + ' · ' + item.label + ' · ' + outcome + ' (sin datos numéricos)');
            if (!running) { return; }
            // Preserve a single iframe and enforce a pause before the next.
            waitTimer = setTimeout(next, delays[Number(speed.value)] || delays[2]);
        });
    }

    start.addEventListener('click', function () {
        if (running) { return; }
        cancelActive();
        const seen = new Set();
        queue = [];
        root.querySelectorAll('.tgb-h-check').forEach(function (button) {
            const kind = button.dataset.kind || '';
            const id = button.dataset.id || '';
            const query = button.dataset.query || '';
            if (!query || seen.has(kind + ':' + id)) { return; }
            seen.add(kind + ':' + id);
            queue.push({button: button, kind: kind, label: query, query: query});
            badge(button, 'En cola');
        });
        if (!queue.length) {
            status.textContent = 'Sin clústeres o hubs disponibles.';
            return;
        }
        done = 0;
        running = true;
        paused = false;
        progress.value = 0;
        controls();
        appendLog('Recorrido iniciado: ' + queue.length + ' nodos únicos; solo España; categorías excluidas.');
        next();
    });

    pause.addEventListener('click', function () {
        if (!running || paused) { return; }
        paused = true;
        if (waitTimer !== null) { clearTimeout(waitTimer); waitTimer = null; }
        status.textContent = 'En pausa; la visualización activa puede terminar de cargar.';
        controls();
        appendLog('Pausa solicitada.');
    });

    resume.addEventListener('click', function () {
        if (!running || !paused) { return; }
        paused = false;
        controls();
        appendLog('Proceso reanudado.');
        waitTimer = setTimeout(next, delays[Number(speed.value)] || delays[2]);
    });

    stop.addEventListener('click', function () {
        if (!running) { return; }
        cancelActive();
        finishRun('Detenido. ' + done + ' consultas visuales solicitadas. No se consultaron categorías.');
    });

    speed.addEventListener('input', function () {
        speedLabel.textContent = speed.value;
    });

    root.querySelectorAll('.tgb-h-check').forEach(function (button) {
        button.addEventListener('click', function () {
            if (running) { return; }
            const name = button.dataset.query || '';
            if (!name) { return; }
            badge(button, 'Gráfico solicitado');
            appendLog('Consulta individual · ' + name);
            show(name, name);
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
            const name = option.dataset.query || option.textContent;
            appendLog('Categoría consultada manualmente · ' + name);
            show(name, name);
        });
    });

    controls();
}());
