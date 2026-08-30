(function () {
    'use strict';

    const config = window.SEODependienteAdmin || {};
    const reindexButton = document.querySelector('[data-dependiente-reindex]');
    const clearButton = document.querySelector('[data-dependiente-clear]');
    const bar = document.querySelector('[data-dependiente-progress-bar]');
    const text = document.querySelector('[data-dependiente-progress-text]');
    const indexed = document.querySelector('[data-dependiente-indexed]');
    const total = document.querySelector('[data-dependiente-total]');

    if (bar) {
        const initialPercent = Math.max(0, Math.min(100, Number(bar.dataset.initialPercent || 0)));
        bar.style.width = initialPercent + '%';
    }

    async function post(action, data) {
        const body = new URLSearchParams(Object.assign({ action, nonce: config.nonce }, data || {}));
        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        });
        const payload = await response.json();
        if (!payload.success) {
            throw new Error((payload.data && payload.data.message) || 'No se pudo completar la operación.');
        }
        return payload.data;
    }

    function updateProgress(data) {
        const totalValue = Number(data.total || (total ? total.textContent.replace(/\D/g, '') : 0));
        const indexedValue = Number(data.indexed || 0);
        const percent = totalValue ? Math.min(100, Math.round((indexedValue / totalValue) * 100)) : 0;
        if (bar) bar.style.width = percent + '%';
        if (text) text.textContent = percent + '% completado' + (data.done ? ' · Índice actualizado' : ' · Procesando lote ' + data.page + ' de ' + data.pages);
        if (indexed) indexed.textContent = new Intl.NumberFormat().format(indexedValue);
        if (total && totalValue) total.textContent = new Intl.NumberFormat().format(totalValue);
    }

    async function reindex() {
        if (!reindexButton) return;
        reindexButton.disabled = true;
        clearButton && (clearButton.disabled = true);
        let page = 1;
        let reset = 1;
        try {
            while (true) {
                const data = await post('seo_dependiente_reindex', { page, reset });
                updateProgress(data);
                if (data.done) break;
                page += 1;
                reset = 0;
            }
        } catch (error) {
            if (text) text.textContent = error.message;
        } finally {
            reindexButton.disabled = false;
            clearButton && (clearButton.disabled = false);
        }
    }

    async function clearIndex() {
        if (!clearButton || !window.confirm('¿Vaciar el índice de Dependiente? El buscador quedará sin resultados hasta reindexar.')) return;
        clearButton.disabled = true;
        try {
            const data = await post('seo_dependiente_clear');
            updateProgress({ indexed: data.indexed, total: total ? total.textContent.replace(/\D/g, '') : 0, done: false, page: 0, pages: 0 });
            if (text) text.textContent = 'Índice vacío. Pulsa “Reindexar catálogo completo”; si alguien abre la página antes, se generará un primer lote automáticamente.';
        } catch (error) {
            if (text) text.textContent = error.message;
        } finally {
            clearButton.disabled = false;
        }
    }

    reindexButton && reindexButton.addEventListener('click', reindex);
    clearButton && clearButton.addEventListener('click', clearIndex);
}());
