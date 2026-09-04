(function () {
    'use strict';

    const config = window.SEODependienteEntrenador || {};
    const root = document.querySelector('[data-trainer-root]');
    if (!root) return;

    const lessonKey = root.dataset.currentLesson || '';
    const currentModule = Number(root.dataset.currentModule || 0);
    const prepareButton = root.querySelector('[data-trainer-prepare-lesson]');
    const runModuleButton = root.querySelector('[data-trainer-run-module]');
    const exportButton = root.querySelector('[data-trainer-export-lesson]');
    const prepareProgress = root.querySelector('[data-trainer-prepare-progress]');
    const prepareBar = root.querySelector('[data-trainer-prepare-bar]');
    const prepareStatus = root.querySelector('[data-trainer-prepare-status]');
    const moduleProgress = root.querySelector('[data-trainer-module-progress]');
    const progressBar = root.querySelector('[data-trainer-progress-bar]');
    const runStatus = root.querySelector('[data-trainer-run-status]');
    const runBody = root.querySelector('[data-trainer-run-body]');

    let busy = false;

    async function post(action, data) {
        const body = new URLSearchParams(Object.assign({ action, nonce: config.nonce || '' }, data || {}));
        let response;
        try {
            response = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
        } catch (cause) {
            const error = new Error((cause && cause.message) || 'No se pudo conectar con el servidor.');
            error.transient = true;
            error.httpStatus = 0;
            throw error;
        }

        const raw = await response.text();
        let payload = null;
        try {
            payload = raw ? JSON.parse(raw) : null;
        } catch (cause) {
            const error = new Error('Respuesta HTTP no válida (' + response.status + ').');
            error.transient = response.status >= 500 || response.status === 429 || response.status === 408 || response.status === 423;
            error.httpStatus = response.status;
            throw error;
        }

        if (!response.ok || !payload || !payload.success) {
            const message = payload && payload.data && payload.data.message
                ? payload.data.message
                : ('HTTP ' + response.status + ': no se pudo completar la operación.');
            const error = new Error(message);
            error.transient = response.status >= 500 || response.status === 429 || response.status === 408 || response.status === 423;
            error.httpStatus = response.status;
            throw error;
        }
        return payload.data || {};
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, Math.max(0, ms));
        });
    }

    function createUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
            const value = Math.floor(Math.random() * 16);
            const result = char === 'x' ? value : ((value & 0x3) | 0x8);
            return result.toString(16);
        });
    }

    function retryDelay(attempt) {
        const delays = [3000, 8000, 20000, 45000, 90000, 120000];
        return delays[Math.min(Math.max(1, attempt), delays.length) - 1];
    }

    function setBusy(value) {
        busy = !!value;
        [prepareButton, runModuleButton, exportButton].forEach(function (button) {
            if (button) button.disabled = busy;
        });
        root.classList.toggle('is-busy', busy);
    }

    function setBar(bar, done, total) {
        if (!bar) return;
        const percent = total > 0 ? Math.max(0, Math.min(100, Math.round((done / total) * 100))) : 0;
        bar.style.width = percent + '%';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateKpis(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            root.querySelectorAll('[data-trainer-kpi="' + key + '"], [data-trainer-summary="' + key + '"]').forEach(function (el) {
                el.textContent = new Intl.NumberFormat().format(Number(summary[key] || 0));
            });
        });
    }

    function evaluationLabel(status) {
        const labels = {
            pass_top1: 'Correcto · Top 1',
            pass_top3: 'Correcto · Top 3',
            pass_top8: 'Correcto · Top 8',
            fail: 'No superado',
            error: 'Error técnico'
        };
        return labels[status] || status || 'Sin evaluar';
    }

    function renderRunRow(row) {
        const status = String(row.evaluation_status || '');
        const statusClass = status.indexOf('pass_') === 0 ? 'is-ok' : (status === 'error' ? 'is-error' : 'is-empty');
        const results = Array.isArray(row.top_results) ? row.top_results : [];
        const resultsHtml = results.length
            ? '<ol class="seo-dependiente-trainer__answer-list">' + results.slice(0, 5).map(function (result) {
                const reasons = Array.isArray(result.reasons) && result.reasons.length
                    ? '<span>' + escapeHtml(result.reasons.join(' · ')) + '</span>'
                    : '';
                return '<li><strong>' + escapeHtml(result.title || '') + '</strong>' + reasons + '</li>';
            }).join('') + '</ol>'
            : '<span class="description">Sin productos devueltos.</span>';

        return '<tr>' +
            '<td><strong>' + Number(row.module_no || 0) + '</strong></td>' +
            '<td><strong>' + escapeHtml(row.question || '') + '</strong>' +
                (row.search_strategy ? '<div class="description">Estrategia: <code>' + escapeHtml(row.search_strategy) + '</code></div>' : '') + '</td>' +
            '<td><span class="seo-dependiente-trainer__status ' + statusClass + '">' + escapeHtml(evaluationLabel(status)) + '</span>' +
                (row.error_message ? '<div class="description">' + escapeHtml(row.error_message) + '</div>' : '') + '</td>' +
            '<td>' + resultsHtml + '</td>' +
            '</tr>';
    }

    function prependRows(rows) {
        if (!runBody || !rows || !rows.length) return;
        const empty = runBody.querySelector('[data-trainer-run-empty]');
        if (empty) empty.remove();
        const html = rows.slice().reverse().map(renderRunRow).join('');
        runBody.insertAdjacentHTML('afterbegin', html);
    }

    async function prepareLesson() {
        if (busy || !lessonKey) return;
        setBusy(true);
        if (prepareProgress) prepareProgress.hidden = false;
        if (prepareStatus) prepareStatus.textContent = 'Preparando el temario desde el catálogo…';

        try {
            let safety = 0;
            while (safety < 10000) {
                safety += 1;
                const data = await post('seo_dependiente_entrenador_prepare_lesson', { lesson_key: lessonKey });
                const done = Number(data.prepare_offset || 0);
                const total = Number(data.prepare_total || 0);
                setBar(prepareBar, done, total || done || 1);
                if (prepareStatus) {
                    prepareStatus.textContent = 'Preparando: ' + done + ' de ' + total + ' fuentes revisadas · ' +
                        Number(data.item_count || 0) + ' ejercicios generados.';
                }
                if (data.done) {
                    if (prepareStatus) {
                        prepareStatus.textContent = 'Lección preparada: ' + Number(data.item_count || 0) + ' ejercicios en ' +
                            Number(data.module_count || 0) + ' módulos. Se estudiará sobre el snapshot ' + Number(data.snapshot_before || 0) + '.';
                    }
                    window.setTimeout(function () { window.location.reload(); }, 500);
                    return;
                }
                await sleep(120);
            }
            throw new Error('La preparación superó el límite de seguridad del navegador.');
        } catch (error) {
            if (prepareStatus) prepareStatus.textContent = error.message;
            setBusy(false);
        }
    }

    async function runModule() {
        if (busy || !lessonKey || currentModule < 1) return;
        setBusy(true);
        if (moduleProgress) moduleProgress.hidden = false;

        const batchMin = Math.max(1, Number(config.batchMin || 1));
        const batchMax = Math.max(batchMin, Number(config.batchMax || 4));
        const fastSeconds = Math.max(0.5, Number(config.fastSeconds || 2.5));
        const slowSeconds = Math.max(fastSeconds + 0.5, Number(config.slowSeconds || 7));
        const hardSeconds = Math.max(slowSeconds + 1, Number(config.hardSeconds || 14));
        const growthFactor = Math.max(1.05, Math.min(2, Number(config.growthFactor || 1.34)));
        const slowdownFactor = Math.max(0.20, Math.min(0.95, Number(config.slowdownFactor || 0.50)));
        const fastStreakRequired = Math.max(1, Number(config.fastStreakRequired || 2));
        const maxRetries = Math.max(1, Number(config.maxRetries || 6));

        let batchSize = Math.max(batchMin, Math.min(batchMax, Number(config.batchSize || 1)));
        let batchUuid = createUuid();
        let retries = 0;
        let fastStreak = 0;
        let lastAnswered = 0;

        if (runStatus) runStatus.textContent = 'Ejecutando módulo ' + currentModule + ' de forma adaptativa…';

        try {
            while (true) {
                const startedAt = performance.now();
                let data;
                try {
                    data = await post('seo_dependiente_entrenador_run_module', {
                        lesson_key: lessonKey,
                        module_no: currentModule,
                        batch_uuid: batchUuid,
                        batch_size: batchSize
                    });
                    retries = 0;
                } catch (error) {
                    if (!error.transient || retries >= maxRetries) throw error;
                    retries += 1;
                    batchSize = batchMin;
                    fastStreak = 0;
                    const wait = retryDelay(retries);
                    if (runStatus) {
                        runStatus.textContent = 'Problema temporal. Reintento ' + retries + '/' + maxRetries +
                            ' en ' + Math.round(wait / 1000) + ' s. Lote reducido a ' + batchSize + '.';
                    }
                    await sleep(wait);
                    continue;
                }

                const duration = Math.max(0, (performance.now() - startedAt) / 1000);
                batchUuid = data.batch_uuid || batchUuid;
                lastAnswered = Number(data.module_answered || lastAnswered);
                const total = Number(data.module_total || 0);
                setBar(progressBar, lastAnswered, total || lastAnswered || 1);
                updateKpis(data.summary || {});
                prependRows(data.rows || []);

                if (duration >= hardSeconds) {
                    batchSize = batchMin;
                    fastStreak = 0;
                } else if (duration >= slowSeconds) {
                    batchSize = Math.max(batchMin, Math.floor(batchSize * slowdownFactor));
                    fastStreak = 0;
                } else if (duration <= fastSeconds) {
                    fastStreak += 1;
                    if (fastStreak >= fastStreakRequired && batchSize < batchMax) {
                        batchSize = Math.min(batchMax, Math.max(batchSize + 1, Math.ceil(batchSize * growthFactor)));
                        fastStreak = 0;
                    }
                } else {
                    fastStreak = 0;
                }

                if (runStatus) {
                    runStatus.textContent = 'Módulo ' + currentModule + ': ' + lastAnswered + ' de ' + total +
                        ' evaluados · ' + duration.toFixed(1) + ' s último lote · siguiente lote: ' + batchSize + '.';
                }

                if (data.module_done || data.lesson_done) {
                    if (runStatus) {
                        runStatus.textContent = data.lesson_done
                            ? 'Lección completada. Se ha creado el nuevo snapshot y la siguiente lección queda desbloqueada.'
                            : 'Módulo completado. Ya puedes continuar con el siguiente módulo.';
                    }
                    window.setTimeout(function () { window.location.reload(); }, 700);
                    return;
                }

                if (Number(data.processed || 0) < 1) {
                    throw new Error('No quedan ejercicios procesables, pero el módulo no se ha podido cerrar.');
                }
                await sleep(duration >= hardSeconds ? 5000 : (duration >= slowSeconds ? 1800 : 350));
            }
        } catch (error) {
            if (runStatus) runStatus.textContent = 'Ejecución detenida: ' + error.message + '. Lo ya evaluado queda guardado.';
            setBusy(false);
        }
    }

    async function exportLesson() {
        if (busy || !lessonKey) return;
        setBusy(true);
        if (runStatus) runStatus.textContent = 'Preparando JSON de la lección…';
        try {
            const data = await post('seo_dependiente_entrenador_export_lesson', { lesson_key: lessonKey });
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = data.filename || ('dependiente-academia-' + lessonKey + '.json');
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            if (runStatus) runStatus.textContent = 'JSON descargado.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'No se pudo descargar el JSON: ' + error.message;
        } finally {
            setBusy(false);
        }
    }

    prepareButton && prepareButton.addEventListener('click', prepareLesson);
    runModuleButton && runModuleButton.addEventListener('click', runModule);
    exportButton && exportButton.addEventListener('click', exportLesson);
}());
