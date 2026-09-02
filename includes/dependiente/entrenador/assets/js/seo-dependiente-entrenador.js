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
    const autoButton = root.querySelector('[data-trainer-mode-auto]');
    const manualButton = root.querySelector('[data-trainer-mode-manual]');
    const autoStatus = root.querySelector('[data-trainer-auto-status]');
    const autoBadge = root.querySelector('[data-trainer-auto-badge]');
    const labRoot = root.querySelector('[data-trainer-lab]');
    const labText = root.querySelector('[data-trainer-lab-text]');
    const labFile = root.querySelector('[data-trainer-lab-file]');
    const labMode = root.querySelector('[data-trainer-lab-mode]');
    const labImportButton = root.querySelector('[data-trainer-lab-import]');
    const labRunButton = root.querySelector('[data-trainer-lab-run]');
    const labExportButton = root.querySelector('[data-trainer-lab-export]');
    const labStatus = root.querySelector('[data-trainer-lab-status]');
    const labProgressBar = root.querySelector('[data-trainer-lab-progress-bar]');
    const labRunBody = root.querySelector('[data-trainer-lab-run-body]');

    let busy = false;
    let autoRunning = root.dataset.autoRunning === '1';
    let labBatchKey = labRoot ? (labRoot.dataset.labBatchKey || '') : '';
    const baseDisabled = new WeakMap();
    [prepareButton, runModuleButton, autoButton, labImportButton, labRunButton].forEach(function (button) {
        if (button) baseDisabled.set(button, !!button.disabled);
    });

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

    async function postForm(action, formData) {
        const body = formData || new FormData();
        body.set('action', action);
        body.set('nonce', config.nonce || '');
        let response;
        try {
            response = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
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
        if (prepareButton) prepareButton.disabled = busy || autoRunning || !!baseDisabled.get(prepareButton);
        if (runModuleButton) runModuleButton.disabled = busy || autoRunning || !!baseDisabled.get(runModuleButton);
        if (exportButton) exportButton.disabled = busy;
        if (autoButton) autoButton.disabled = busy || autoRunning || !!baseDisabled.get(autoButton);
        if (manualButton) manualButton.disabled = busy || !autoRunning;
        if (labImportButton) labImportButton.disabled = busy || autoRunning || !!baseDisabled.get(labImportButton);
        if (labRunButton) labRunButton.disabled = busy || autoRunning || !!baseDisabled.get(labRunButton);
        if (labExportButton) labExportButton.disabled = busy;
        if (labFile) labFile.disabled = busy;
        if (labText) labText.disabled = busy;
        if (labMode) labMode.disabled = busy;
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
            error: 'Error técnico',
            observed: 'Observada · sin aprendizaje'
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
                    batchSize = Math.max(batchMin, Math.floor(batchSize / 2));
                    fastStreak = 0;
                } else if (duration <= fastSeconds) {
                    fastStreak += 1;
                    if (fastStreak >= 2 && batchSize < batchMax) {
                        batchSize += 1;
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

    function describeAutomation(data) {
        const state = data && data.state ? data.state : {};
        const current = data && data.current ? data.current : null;
        if (state.last_error) {
            return 'Automático detenido: ' + state.last_error;
        }
        if (state.last_message) {
            return state.last_message;
        }
        if (data && data.running && current) {
            return 'Automático activo · Lección ' + Number(current.lesson_order || 0) + ' · ' + String(current.title || '') +
                (Number(current.next_module || 0) > 0 ? ' · módulo ' + Number(current.next_module || 0) : '') + '.';
        }
        return 'Modo manual: tú decides cuándo preparar y ejecutar cada módulo.';
    }

    function applyAutomationState(data) {
        const wasRunning = autoRunning;
        autoRunning = !!(data && data.running);
        root.dataset.autoRunning = autoRunning ? '1' : '0';
        if (autoBadge) {
            autoBadge.textContent = autoRunning ? 'Automático activo' : ((data && data.state && data.state.status === 'completed') ? 'Completado' : 'Manual');
            autoBadge.classList.toggle('is-running', autoRunning);
        }
        if (autoStatus) autoStatus.textContent = describeAutomation(data);
        setBusy(false);

        if (wasRunning && !autoRunning && data && data.state && ['completed', 'error'].includes(String(data.state.status || ''))) {
            window.setTimeout(function () { window.location.reload(); }, 1200);
        }
    }

    async function setTrainingMode(mode) {
        if (busy) return;
        setBusy(true);
        if (autoStatus) {
            autoStatus.textContent = mode === 'auto'
                ? 'Activando formación automática…'
                : 'Pausando después del lote que esté en curso…';
        }
        try {
            const data = await post('seo_dependiente_entrenador_set_mode', { mode: mode });
            applyAutomationState(data);
            window.setTimeout(function () { window.location.reload(); }, 500);
        } catch (error) {
            if (autoStatus) autoStatus.textContent = error.message;
            setBusy(false);
        }
    }

    async function pollAutomation() {
        if (!autoRunning) return;
        try {
            const data = await post('seo_dependiente_entrenador_auto_status', {});
            applyAutomationState(data);
        } catch (error) {
            if (autoStatus) autoStatus.textContent = 'No se pudo actualizar el estado automático: ' + error.message;
        }
        if (autoRunning) {
            window.setTimeout(pollAutomation, Math.max(2000, Number(config.autoPollMs || 5000)));
        }
    }

    function updateLabSummary(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            root.querySelectorAll('[data-trainer-lab-summary="' + key + '"]').forEach(function (el) {
                el.textContent = new Intl.NumberFormat().format(Number(summary[key] || 0));
            });
        });
        setBar(labProgressBar, Number(summary && summary.answered || 0), Number(summary && summary.total || 0));
    }

    function renderLabRunRow(row) {
        const results = Array.isArray(row.top_results) ? row.top_results : [];
        const resultsHtml = results.length
            ? '<ol class="seo-dependiente-trainer__answer-list">' + results.slice(0, 5).map(function (result) {
                const reasons = Array.isArray(result.reasons) && result.reasons.length
                    ? '<span>' + escapeHtml(result.reasons.join(' · ')) + '</span>'
                    : '';
                return '<li><strong>' + escapeHtml(result.title || '') + '</strong>' + reasons + '</li>';
            }).join('') + '</ol>'
            : '<span class="description">Sin productos devueltos.</span>';
        const isError = String(row.status || '') === 'error';
        return '<tr>' +
            '<td><strong>' + escapeHtml(row.question || '') + '</strong>' +
                (row.search_strategy ? '<div class="description">Estrategia: <code>' + escapeHtml(row.search_strategy) + '</code></div>' : '') + '</td>' +
            '<td><span class="seo-dependiente-trainer__status ' + (isError ? 'is-error' : 'is-neutral') + '">' + (isError ? 'Error técnico' : 'Observada') + '</span>' +
                (row.error_message ? '<div class="description">' + escapeHtml(row.error_message) + '</div>' : '') + '</td>' +
            '<td>' + resultsHtml + '</td>' +
            '</tr>';
    }

    function prependLabRows(rows) {
        if (!labRunBody || !rows || !rows.length) return;
        const empty = labRunBody.querySelector('[data-trainer-lab-run-empty]');
        if (empty) empty.remove();
        labRunBody.insertAdjacentHTML('afterbegin', rows.slice().reverse().map(renderLabRunRow).join(''));
    }

    async function importLabBatch() {
        if (busy || !labRoot) return;
        const text = labText ? labText.value.trim() : '';
        const file = labFile && labFile.files && labFile.files[0] ? labFile.files[0] : null;
        if (!text && !file) {
            if (labStatus) labStatus.textContent = 'Escribe al menos una pregunta o selecciona un archivo.';
            return;
        }
        setBusy(true);
        if (labStatus) labStatus.textContent = 'Preparando el lote de preguntas…';
        try {
            const form = new FormData();
            form.set('questions_text', text);
            form.set('mode', labMode ? labMode.value : 'need');
            if (file) form.set('lab_file', file, file.name);
            const data = await postForm('seo_dependiente_entrenador_lab_import', form);
            labBatchKey = data.batch_key || '';
            if (labStatus) labStatus.textContent = data.message || 'Lote preparado.';
            window.setTimeout(function () { window.location.reload(); }, 500);
        } catch (error) {
            if (labStatus) labStatus.textContent = 'No se pudo preparar el lote: ' + error.message;
            setBusy(false);
        }
    }

    async function runLabBatch() {
        if (busy || !labBatchKey) return;
        setBusy(true);
        const batchMin = Math.max(1, Number(config.batchMin || 1));
        const batchMax = Math.max(batchMin, Number(config.batchMax || 4));
        const fastSeconds = Math.max(0.5, Number(config.fastSeconds || 2.5));
        const slowSeconds = Math.max(fastSeconds + 0.5, Number(config.slowSeconds || 7));
        const hardSeconds = Math.max(slowSeconds + 1, Number(config.hardSeconds || 14));
        const maxRetries = Math.max(1, Number(config.maxRetries || 6));
        let batchSize = Math.max(batchMin, Math.min(batchMax, Number(config.batchSize || 1)));
        let batchUuid = createUuid();
        let retries = 0;
        let fastStreak = 0;
        let noProgress = 0;
        let lastAnswered = -1;
        if (labStatus) labStatus.textContent = 'Ejecutando el lote de forma adaptativa…';

        try {
            while (true) {
                const startedAt = performance.now();
                let data;
                try {
                    data = await post('seo_dependiente_entrenador_lab_run', {
                        batch_key: labBatchKey,
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
                    if (labStatus) labStatus.textContent = 'Problema temporal. Reintento ' + retries + '/' + maxRetries + ' en ' + Math.round(wait / 1000) + ' s.';
                    await sleep(wait);
                    continue;
                }

                const duration = Math.max(0, (performance.now() - startedAt) / 1000);
                batchUuid = data.batch_uuid || batchUuid;
                const summary = data.summary || {};
                const answered = Number(summary.answered || 0);
                const total = Number(summary.total || 0);
                updateLabSummary(summary);
                prependLabRows(data.rows || []);

                if (answered <= lastAnswered) noProgress += 1; else noProgress = 0;
                lastAnswered = answered;
                if (noProgress >= 3) throw new Error('El lote no avanza después de varios intentos. Se conserva todo lo ya ejecutado.');

                if (duration >= hardSeconds) {
                    batchSize = batchMin;
                    fastStreak = 0;
                } else if (duration >= slowSeconds) {
                    batchSize = Math.max(batchMin, Math.floor(batchSize / 2));
                    fastStreak = 0;
                } else if (duration <= fastSeconds) {
                    fastStreak += 1;
                    if (fastStreak >= 2 && batchSize < batchMax) {
                        batchSize += 1;
                        fastStreak = 0;
                    }
                } else {
                    fastStreak = 0;
                }

                if (labStatus) labStatus.textContent = 'Lote: ' + answered + ' de ' + total + ' preguntas · ' + duration.toFixed(1) + ' s último lote · siguiente lote: ' + batchSize + '.';
                if (data.done) {
                    if (labStatus) labStatus.textContent = 'Lote completado. Estas preguntas han sido solo de diagnóstico y no han modificado el conocimiento.';
                    window.setTimeout(function () { window.location.reload(); }, 700);
                    return;
                }
                if (Number(data.processed || 0) < 1) throw new Error('No quedan preguntas procesables, pero el lote no se ha podido cerrar.');
                await sleep(duration >= hardSeconds ? 5000 : (duration >= slowSeconds ? 1800 : 350));
            }
        } catch (error) {
            if (labStatus) labStatus.textContent = 'Ejecución detenida: ' + error.message;
            setBusy(false);
        }
    }

    async function exportLabBatch() {
        if (busy || !labBatchKey) return;
        setBusy(true);
        if (labStatus) labStatus.textContent = 'Preparando JSON del Laboratorio…';
        try {
            const data = await post('seo_dependiente_entrenador_lab_export', { batch_key: labBatchKey });
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = data.filename || ('dependiente-laboratorio-' + labBatchKey + '.json');
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            if (labStatus) labStatus.textContent = 'JSON descargado.';
        } catch (error) {
            if (labStatus) labStatus.textContent = 'No se pudo descargar el JSON: ' + error.message;
        } finally {
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
    autoButton && autoButton.addEventListener('click', function () { setTrainingMode('auto'); });
    manualButton && manualButton.addEventListener('click', function () { setTrainingMode('manual'); });
    labImportButton && labImportButton.addEventListener('click', importLabBatch);
    labRunButton && labRunButton.addEventListener('click', runLabBatch);
    labExportButton && labExportButton.addEventListener('click', exportLabBatch);

    setBusy(false);
    if (autoRunning) window.setTimeout(pollAutomation, 1500);
}());
