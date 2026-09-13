(function () {
    'use strict';

    const config = window.SEODependienteAdmin || {};
    const reindexButton = document.querySelector('[data-dependiente-reindex]');
    const clearButton = document.querySelector('[data-dependiente-clear]');
    const bar = document.querySelector('[data-dependiente-progress-bar]');
    const text = document.querySelector('[data-dependiente-progress-text]');
    const indexed = document.querySelector('[data-dependiente-indexed]');
    const total = document.querySelector('[data-dependiente-total]');
    const resetRoot = document.querySelector('[data-dependiente-reset-root]');
    const resetPrepare = document.querySelector('[data-dependiente-reset-prepare]');
    const resetConfirmPanel = document.querySelector('[data-dependiente-reset-confirm]');
    const resetConfirmButton = document.querySelector('[data-dependiente-reset-confirm-button]');
    const resetCancel = document.querySelector('[data-dependiente-reset-cancel]');
    const resetStatus = document.querySelector('[data-dependiente-reset-status]');
    let statusTimer = null;
    let lastKnownStatus = '';

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

    function numberFormat(value) {
        return new Intl.NumberFormat().format(Math.max(0, Number(value || 0)));
    }

    function setControls(status) {
        const running = status === 'running';
        if (reindexButton) reindexButton.disabled = running;
        if (clearButton) clearButton.disabled = false;
    }

    function updateProgress(data) {
        data = data || {};
        const status = String(data.status || 'idle');
        const totalValue = Number(data.total || (total ? total.textContent.replace(/\D/g, '') : 0));
        const indexedValue = Number(data.indexed || 0);
        let percent = Number.isFinite(Number(data.percent))
            ? Number(data.percent)
            : (totalValue ? Math.round((indexedValue / totalValue) * 100) : 0);
        percent = Math.max(0, Math.min(100, percent));

        lastKnownStatus = status;
        if (bar) bar.style.width = percent + '%';
        if (indexed) indexed.textContent = numberFormat(indexedValue);
        if (total && totalValue) total.textContent = numberFormat(totalValue);
        setControls(status);

        if (!text) return;

        if (status === 'running') {
            const page = Math.max(1, Number(data.page || 1));
            const pages = Math.max(1, Number(data.pages || 1));
            text.textContent = percent + '% completado · Reindexando en segundo plano · lote ' + Math.min(page, pages) + ' de ' + pages + '. Puedes cerrar esta pantalla.';
            return;
        }
        if (status === 'completed' && Number(data.verified || 0) === 1) {
            text.textContent = '100% · ÍNDICE COMPLETO Y VERIFICADO';
            return;
        }
        if (status === 'failed') {
            const missing = Math.max(0, Number(data.missing || 0));
            const extra = Math.max(0, Number(data.extra || 0));
            if (missing || extra) {
                const parts = [];
                if (missing) parts.push('faltan ' + numberFormat(missing) + ' productos');
                if (extra) parts.push('sobran ' + numberFormat(extra) + ' filas');
                text.textContent = 'ÍNDICE INCOMPLETO · ' + parts.join(' · ') + '. ' + String(data.last_error || '');
            } else {
                text.textContent = 'REINDEXACIÓN FALLIDA · ' + String(data.last_error || 'Revisa el estado del proceso.');
            }
            return;
        }
        if (status === 'stopped' && indexedValue === 0) {
            text.textContent = 'Índice vacío. Pulsa “Reindexar catálogo completo” cuando quieras iniciarlo.';
            return;
        }
        text.textContent = percent + '% completado';
    }

    function cancelStatusPoll() {
        if (statusTimer) {
            window.clearTimeout(statusTimer);
            statusTimer = null;
        }
    }

    function scheduleStatusPoll(delay) {
        cancelStatusPoll();
        statusTimer = window.setTimeout(function () {
            pollStatus();
        }, Math.max(1000, Number(delay || 4000)));
    }

    async function pollStatus(initial) {
        try {
            const data = await post('seo_dependiente_reindex_status');
            updateProgress(data);
            if (String(data.status || '') === 'running') {
                scheduleStatusPoll(4000);
            } else {
                cancelStatusPoll();
            }
        } catch (error) {
            if (!initial && text && lastKnownStatus === 'running') {
                text.textContent = 'No se ha podido consultar el estado. Esto no detiene la reindexación en segundo plano; volveré a comprobarla.';
            }
            if (lastKnownStatus === 'running') {
                scheduleStatusPoll(6000);
            }
        }
    }

    async function reindex() {
        if (!reindexButton) return;
        cancelStatusPoll();
        reindexButton.disabled = true;
        if (text) text.textContent = 'Iniciando reindexación…';
        try {
            const data = await post('seo_dependiente_reindex');
            updateProgress(data);
            if (String(data.status || '') === 'running') {
                scheduleStatusPoll(2500);
            }
        } catch (error) {
            if (text) text.textContent = error.message;
            reindexButton.disabled = false;
        }
    }

    async function clearIndex() {
        if (!clearButton || !window.confirm('¿Vaciar el índice de Dependiente? Si hay una reindexación en curso, se detendrá. El buscador quedará sin resultados hasta reindexar.')) return;
        cancelStatusPoll();
        clearButton.disabled = true;
        try {
            const data = await post('seo_dependiente_clear');
            updateProgress({ indexed: data.indexed, total: total ? total.textContent.replace(/\D/g, '') : 0, status: 'stopped', percent: 0 });
        } catch (error) {
            if (text) text.textContent = error.message;
        } finally {
            clearButton.disabled = false;
        }
    }

    function showResetConfirmation() {
        if (!resetConfirmPanel) return;
        resetConfirmPanel.hidden = false;
        resetConfirmPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        resetConfirmButton && resetConfirmButton.focus();
    }

    function hideResetConfirmation() {
        if (resetConfirmPanel) resetConfirmPanel.hidden = true;
        if (resetStatus) {
            resetStatus.textContent = '';
            resetStatus.classList.remove('is-error', 'is-success');
        }
    }

    async function resetKnowledge() {
        if (!resetConfirmButton) return;
        cancelStatusPoll();
        resetConfirmButton.disabled = true;
        resetCancel && (resetCancel.disabled = true);
        resetPrepare && (resetPrepare.disabled = true);
        if (resetStatus) {
            resetStatus.textContent = 'Reiniciando conocimiento…';
            resetStatus.classList.remove('is-error', 'is-success');
        }
        try {
            const data = await post('seo_dependiente_reset_knowledge', { confirmation: 'BORRAR_CONOCIMIENTO' });
            if (indexed) indexed.textContent = '0';
            if (bar) bar.style.width = '0%';
            lastKnownStatus = 'stopped';
            setControls('stopped');
            if (text) text.textContent = 'Índice vacío. Reindexa el catálogo antes de iniciar la primera lección.';
            if (resetStatus) {
                resetStatus.textContent = data.message || 'Conocimiento reiniciado correctamente.';
                resetStatus.classList.add('is-success');
            }
            if (resetRoot) {
                resetRoot.querySelectorAll('[data-reset-zero]').forEach(function (node) {
                    node.textContent = '0';
                });
            }
            if (resetConfirmPanel) resetConfirmPanel.hidden = true;
        } catch (error) {
            if (resetStatus) {
                resetStatus.textContent = error.message;
                resetStatus.classList.add('is-error');
            }
        } finally {
            resetConfirmButton.disabled = false;
            resetCancel && (resetCancel.disabled = false);
            resetPrepare && (resetPrepare.disabled = false);
        }
    }

    document.querySelectorAll('[data-dependiente-media-field]').forEach(function (field) {
        const select = field.querySelector('[data-dependiente-media-select]');
        const clear = field.querySelector('[data-dependiente-media-clear]');
        const input = field.querySelector('[data-dependiente-media-id]');
        const preview = field.querySelector('[data-dependiente-media-preview]');
        const fallback = preview ? (preview.dataset.fallbackSrc || preview.getAttribute('src') || '') : '';

        select && select.addEventListener('click', function () {
            if (!window.wp || !wp.media) return;
            const frame = wp.media({
                title: 'Elegir imagen para Dependiente',
                library: { type: 'image' },
                button: { text: 'Usar esta imagen' },
                multiple: false
            });
            frame.on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                if (input) input.value = attachment.id || 0;
                if (preview) preview.src = (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url) || fallback;
            });
            frame.open();
        });

        clear && clear.addEventListener('click', function () {
            if (input) input.value = '0';
            if (preview) preview.src = fallback;
        });
    });

    reindexButton && reindexButton.addEventListener('click', reindex);
    clearButton && clearButton.addEventListener('click', clearIndex);
    resetPrepare && resetPrepare.addEventListener('click', showResetConfirmation);
    resetCancel && resetCancel.addEventListener('click', hideResetConfirmation);
    resetConfirmButton && resetConfirmButton.addEventListener('click', resetKnowledge);

    // Consultar el estado al abrir la pantalla nunca inicia trabajo; solo permite
    // reconectar el panel a una reindexación manual que ya esté en curso.
    if (reindexButton || text) {
        pollStatus(true);
    }
}());
