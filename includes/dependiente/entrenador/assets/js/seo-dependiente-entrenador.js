(function () {
    'use strict';

    const config = window.SEODependienteEntrenador || {};
    const root = document.querySelector('[data-trainer-root]');
    if (!root) return;

    const lessonKey = root.dataset.currentLesson || '';
    const currentModule = Number(root.dataset.currentModule || 0);
    const prepareButton = root.querySelector('[data-trainer-prepare-lesson]');
    const runModuleButton = root.querySelector('[data-trainer-run-module]');
    const exportLessonButtons = Array.from(root.querySelectorAll('[data-trainer-export-lesson], [data-trainer-export-lesson-key]'));
    const exportProgressButtons = Array.from(root.querySelectorAll('[data-trainer-export-progress], [data-trainer-export-progress-key]'));
    const exportCourseButton = root.querySelector('[data-trainer-export-course]');
    const prepareProgress = root.querySelector('[data-trainer-prepare-progress]');
    const prepareBar = root.querySelector('[data-trainer-prepare-bar]');
    const prepareStatus = root.querySelector('[data-trainer-prepare-status]');
    const moduleProgress = root.querySelector('[data-trainer-module-progress]');
    const progressBar = root.querySelector('[data-trainer-progress-bar]');
    const runStatus = root.querySelector('[data-trainer-run-status]');
    const runBody = root.querySelector('[data-trainer-run-body]');
    const autoButton = root.querySelector('[data-trainer-mode-auto]');
    const manualButton = root.querySelector('[data-trainer-mode-manual]');
    const stopButton = root.querySelector('[data-trainer-mode-stop]');
    const autoStatus = root.querySelector('[data-trainer-auto-status]');
    const autoBadge = root.querySelector('[data-trainer-auto-badge]');
    const labRoot = root.querySelector('[data-trainer-lab]');
    const labText = root.querySelector('[data-trainer-lab-text]');
    const labFile = root.querySelector('[data-trainer-lab-file]');
    const labImportButton = root.querySelector('[data-trainer-lab-import]');
    const labRescanButton = root.querySelector('[data-trainer-lab-rescan]');
    const labExportButton = root.querySelector('[data-trainer-lab-export]');
    const labReportButtons = Array.from(root.querySelectorAll('[data-trainer-lab-report-key]'));
    const labRowExportButtons = Array.from(root.querySelectorAll('[data-trainer-lab-export-key]'));
    const labStatus = root.querySelector('[data-trainer-lab-status]');
    const labProgressBar = root.querySelector('[data-trainer-lab-progress-bar]');
    const labRunBody = root.querySelector('[data-trainer-lab-run-body]');
    const updateRoot = root.querySelector('[data-trainer-update]');
    const updateStartButton = root.querySelector('[data-trainer-update-start]');
    const updateExportButton = root.querySelector('[data-trainer-update-export]');
    const updateStatus = root.querySelector('[data-trainer-update-status]');

    let busy = false;
    let autoRunning = root.dataset.autoRunning === '1';
    let labBatchKey = labRoot ? (labRoot.dataset.labBatchKey || '') : '';
    const baseDisabled = new WeakMap();
    [prepareButton, runModuleButton, autoButton, manualButton, stopButton, labImportButton, labRescanButton, labExportButton, exportCourseButton, updateStartButton, updateExportButton]
        .concat(exportLessonButtons, exportProgressButtons)
        .forEach(function (button) {
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
        exportLessonButtons.forEach(function (button) { button.disabled = busy || !!baseDisabled.get(button); });
        exportProgressButtons.forEach(function (button) { button.disabled = busy || !!baseDisabled.get(button); });
        if (exportCourseButton) exportCourseButton.disabled = busy || !!baseDisabled.get(exportCourseButton);
        if (autoButton) autoButton.disabled = busy || autoRunning || !!baseDisabled.get(autoButton);
        if (manualButton) manualButton.disabled = busy || !autoRunning;
        if (stopButton) stopButton.disabled = busy || !!baseDisabled.get(stopButton);
        if (labImportButton) labImportButton.disabled = busy || autoRunning || !!baseDisabled.get(labImportButton);
        if (labExportButton) labExportButton.disabled = busy || !!baseDisabled.get(labExportButton);
        if (labFile) labFile.disabled = busy;
        if (labText) labText.disabled = busy;
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
            observed: 'Respondida · sin aprendizaje'
        };
        return labels[status] || status || 'Sin evaluar';
    }

    function diagnosticLabel(type) {
        const labels = {
            mastered: 'Conocimiento resuelto',
            parser_gap: 'Fallo de interpretación',
            retrieval_gap: 'Fallo de recuperación',
            ranking_gap: 'Fallo de ranking/filtro',
            clarification_gap: 'Necesita aclaración',
            low_confidence: 'Confianza insuficiente',
            semantic_coverage_gap: 'Cobertura semántica insuficiente',
            teacher_answer_mismatch: 'No coincide con la respuesta de referencia',
            curriculum_invalid: 'Pregunta a revisar',
            technical_error: 'Error técnico',
            observed: 'Observación'
        };
        return labels[type] || type || '';
    }

    function renderRunRow(row) {
        const status = String(row.evaluation_status || '');
        const statusClass = status.indexOf('pass_') === 0 ? 'is-ok' : (status === 'error' ? 'is-error' : 'is-empty');
        const results = Array.isArray(row.top_results) ? row.top_results : [];
        const evaluation = row.evaluation && typeof row.evaluation === 'object' ? row.evaluation : {};
        const diagnostic = diagnosticLabel(String(evaluation.diagnostic_type || ''));
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
                (diagnostic ? '<div class="description">' + escapeHtml(diagnostic) + '</div>' : '') +
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

                if (String(data.lesson_status || '') === 'needs_training') {
                    if (runStatus) {
                        const gate = data.quality_gate || {};
                        const achieved = Math.round(Number(gate.pass_any_ratio || 0) * 1000) / 10;
                        const required = Math.round(Number(gate.min_pass_any || 0) * 1000) / 10;
                        runStatus.textContent = 'Lección evaluada, pero no supera el quality gate (' + achieved + '% / mínimo ' + required + '%). ' +
                            'No se ha creado snapshot ni promocionado conocimiento.';
                    }
                    window.setTimeout(function () { window.location.reload(); }, 900);
                    return;
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
            if (Number(current.lesson_order || 0) === 0 && String(current.title || '') === 'Actualización') {
                return 'Automático activo · Actualización' +
                    (Number(current.next_module || 0) > 0 ? ' · módulo ' + Number(current.next_module || 0) : '') + '.';
            }
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
            const stateStatus = String(data && data.state ? data.state.status || '' : '');
            autoBadge.textContent = autoRunning
                ? 'Automático activo'
                : (stateStatus === 'stopped'
                    ? 'Detenido'
                    : (stateStatus === 'completed' ? 'Completado' : (stateStatus === 'needs_training' ? 'Necesita entrenamiento' : 'Manual')));
            autoBadge.classList.toggle('is-running', autoRunning);
        }
        if (autoStatus) autoStatus.textContent = describeAutomation(data);
        setBusy(false);

        if (wasRunning && !autoRunning && data && data.state && ['completed', 'error', 'needs_training', 'stopped'].includes(String(data.state.status || ''))) {
            window.setTimeout(function () { window.location.reload(); }, 1200);
        }
    }

    async function setTrainingMode(mode) {
        if (busy) return;
        setBusy(true);
        if (autoStatus) {
            autoStatus.textContent = mode === 'auto'
                ? 'Activando formación automática…'
                : (mode === 'stop'
                    ? 'Deteniendo la formación; se conservará el progreso…'
                    : 'Pausando después del lote que esté en curso…');
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
        const evaluation = row.evaluation && typeof row.evaluation === 'object' ? row.evaluation : {};
        const diagnostic = diagnosticLabel(String(evaluation.diagnostic_type || ''));
        const resultsHtml = results.length
            ? '<ol class="seo-dependiente-trainer__answer-list">' + results.slice(0, 5).map(function (result) {
                const reasons = Array.isArray(result.reasons) && result.reasons.length
                    ? '<span>' + escapeHtml(result.reasons.join(' · ')) + '</span>'
                    : '';
                return '<li><strong>' + escapeHtml(result.title || '') + '</strong>' + reasons + '</li>';
            }).join('') + '</ol>'
            : '<span class="description">Sin productos devueltos.</span>';
        const isError = String(row.status || '') === 'error';
        const evaluationStatus = String(row.evaluation_status || evaluation.status || '');
        const learned = evaluationStatus.indexOf('pass_') === 0;
        const statusClass = isError ? 'is-error' : (learned ? 'is-ok' : 'is-empty');
        const statusLabel = isError ? 'Error técnico' : (learned ? 'Aprendida' : 'No aprendida');
        return '<tr>' +
            '<td><strong>' + escapeHtml(row.question || '') + '</strong>' +
                (row.search_strategy ? '<div class="description">Estrategia: <code>' + escapeHtml(row.search_strategy) + '</code></div>' : '') + '</td>' +
            '<td><span class="seo-dependiente-trainer__status ' + statusClass + '">' + statusLabel + '</span>' +
                (diagnostic ? '<div class="description">' + escapeHtml(diagnostic) + '</div>' : '') +
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
        const files = labFile && labFile.files ? Array.from(labFile.files) : [];
        if (!text && !files.length) {
            if (labStatus) labStatus.textContent = 'Escribe al menos una pregunta o selecciona uno o varios archivos.';
            return;
        }

        setBusy(true);
        let queuedFiles = 0;
        let queuedQuestions = 0;
        try {
            // Si hay texto libre se guarda primero como un lote propio.
            if (text) {
                if (labStatus) labStatus.textContent = 'Añadiendo preguntas escritas a la cola…';
                const form = new FormData();
                form.set('questions_text', text);
                const data = await postForm('seo_dependiente_entrenador_lab_import', form);
                labBatchKey = data.batch_key || labBatchKey;
                queuedQuestions += Number(data.created || 0);
            }

            // Cada archivo se sube por separado: evita superar post_max_size y
            // conserva un lote identificable por archivo en la cola.
            for (let i = 0; i < files.length; i += 1) {
                const file = files[i];
                if (labStatus) {
                    labStatus.textContent = 'Añadiendo archivo ' + (i + 1) + ' de ' + files.length + ': ' + file.name + '…';
                }
                const form = new FormData();
                form.set('lab_file', file, file.name);
                const data = await postForm('seo_dependiente_entrenador_lab_import', form);
                labBatchKey = data.batch_key || labBatchKey;
                queuedFiles += 1;
                queuedQuestions += Number(data.created || 0);
            }

            if (labStatus) {
                const parts = [];
                if (queuedFiles) parts.push(queuedFiles + ' archivo' + (queuedFiles === 1 ? '' : 's'));
                if (text) parts.push('preguntas escritas');
                labStatus.textContent = 'Añadidos a la cola: ' + parts.join(' + ') + ' · ' +
                    new Intl.NumberFormat().format(queuedQuestions) + ' preguntas. Academia continuará en segundo plano.';
            }
            window.setTimeout(function () { window.location.reload(); }, 900);
        } catch (error) {
            if (labStatus) labStatus.textContent = 'No se pudo añadir todo a la cola: ' + error.message;
            setBusy(false);
        }
    }

    async function rescanLabFailures() {
        if (busy || !labBatchKey || !labRescanButton) return;
        setBusy(true);
        if (labStatus) labStatus.textContent = 'Preparando un nuevo intento de las preguntas no aprendidas…';
        try {
            const data = await post('seo_dependiente_entrenador_lab_rescan', { batch_key: labBatchKey });
            if (labStatus) labStatus.textContent = data.message || 'Preguntas no aprendidas preparadas.';
            window.setTimeout(function () { window.location.reload(); }, 600);
        } catch (error) {
            if (labStatus) labStatus.textContent = 'No se pudo preparar el reintento: ' + error.message;
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

    function labDiagnosticLabel(value) {
        const labels = {
            mastered: 'Aprendida',
            clarification_gap: 'Necesita aclaración',
            low_confidence: 'Confianza insuficiente',
            semantic_coverage_gap: 'Cobertura semántica insuficiente',
            ranking_gap: 'Fallo de ranking/estabilidad',
            parser_gap: 'Fallo de interpretación',
            retrieval_gap: 'Sin candidatos',
            technical_error: 'Error técnico',
            teacher_answer_mismatch: 'No coincide con respuesta de referencia',
            unknown: 'Sin diagnóstico'
        };
        return labels[value] || String(value || '').replace(/_/g, ' ');
    }

    function renderLabLearningReport(data) {
        const summary = data.summary || {};
        const diagnostics = data.diagnostics || {};
        const averages = data.averages || {};
        const examples = data.examples || {};
        const total = Number(summary.total || 0);
        const answered = Number(summary.answered || 0);
        const learned = Number(summary.learned || 0);
        const failed = Number(summary.failed || 0);
        const errors = Number(summary.errors || 0);
        const rate = answered ? ((learned / answered) * 100).toFixed(1) : '0.0';

        const diagnosticRows = Object.keys(diagnostics).map(function (key) {
            return '<tr><td>' + escapeHtml(labDiagnosticLabel(key)) + '</td><td><strong>' +
                escapeHtml(String(diagnostics[key])) + '</strong></td></tr>';
        }).join('');

        function exampleList(items, title) {
            if (!Array.isArray(items) || !items.length) return '';
            return '<h4>' + escapeHtml(title) + '</h4><ol>' + items.map(function (item) {
                const top = Array.isArray(item.top_results) ? item.top_results.slice(0, 3) : [];
                const products = top.length
                    ? '<div class="description">Top: ' + top.map(function (p) { return escapeHtml(p.title || ''); }).join(' · ') + '</div>'
                    : '';
                const details = [
                    item.diagnostic ? labDiagnosticLabel(item.diagnostic) : '',
                    item.confidence !== null && item.confidence !== undefined ? 'confianza ' + item.confidence : '',
                    item.coverage_ratio !== null && item.coverage_ratio !== undefined ? 'cobertura ' + Math.round(Number(item.coverage_ratio) * 100) + '%' : '',
                    item.stability !== null && item.stability !== undefined ? 'estabilidad ' + Math.round(Number(item.stability) * 100) + '%' : '',
                    item.needs_choice ? 'requiere aclaración' : ''
                ].filter(Boolean).join(' · ');
                return '<li><strong>' + escapeHtml(item.question || '') + '</strong>' +
                    (details ? '<div class="description">' + escapeHtml(details) + '</div>' : '') +
                    (item.reason ? '<div class="description">' + escapeHtml(item.reason) + '</div>' : '') +
                    products + '</li>';
            }).join('') + '</ol>';
        }

        return '<div class="seo-dependiente-trainer__learning-report">' +
            '<p><strong>' + escapeHtml(data.filename || 'Lote') + '</strong> · ' +
            answered.toLocaleString() + ' / ' + total.toLocaleString() + ' evaluadas · ' +
            learned.toLocaleString() + ' aprendidas · ' + failed.toLocaleString() + ' no aprendidas · ' +
            errors.toLocaleString() + ' errores.</p>' +
            '<p><strong>Tasa de aprendizaje sobre evaluadas:</strong> ' + rate + '%' +
            (averages.evaluation_score !== null && averages.evaluation_score !== undefined
                ? ' · score medio ' + Number(averages.evaluation_score).toFixed(3)
                : '') +
            (averages.execution_ms !== null && averages.execution_ms !== undefined
                ? ' · tiempo medio ' + (Number(averages.execution_ms) / 1000).toFixed(2) + ' s'
                : '') + '</p>' +
            (diagnosticRows
                ? '<table class="widefat striped"><thead><tr><th>Diagnóstico</th><th>Preguntas</th></tr></thead><tbody>' + diagnosticRows + '</tbody></table>'
                : '<p class="description">Este lote todavía no tiene evaluaciones.</p>') +
            exampleList(examples.learned || [], 'Ejemplos aprendidos') +
            exampleList(examples.failed || [], 'Ejemplos no aprendidos') +
            '</div>';
    }

    async function showLabLearningReport(event) {
        const button = event && event.currentTarget ? event.currentTarget : null;
        const key = button && button.dataset ? String(button.dataset.trainerLabReportKey || '') : '';
        if (!key) return;
        const row = root.querySelector('[data-trainer-lab-report-row="' + key + '"]');
        const body = root.querySelector('[data-trainer-lab-report-body="' + key + '"]');
        if (!row || !body) return;

        if (row.style.display !== 'none' && body.dataset.loaded === '1') {
            row.style.display = 'none';
            return;
        }

        row.style.display = '';
        body.innerHTML = '<p class="description">Calculando aprendizaje del lote…</p>';
        try {
            const data = await post('seo_dependiente_entrenador_lab_report', { batch_key: key });
            body.innerHTML = renderLabLearningReport(data);
            body.dataset.loaded = '1';
        } catch (error) {
            body.innerHTML = '<p class="description">No se pudo cargar el informe: ' + escapeHtml(error.message) + '</p>';
        }
    }

    async function exportLabBatchByKey(event) {
        const button = event && event.currentTarget ? event.currentTarget : null;
        const key = button && button.dataset ? String(button.dataset.trainerLabExportKey || '') : '';
        if (!key || busy) return;
        setBusy(true);
        if (labStatus) labStatus.textContent = 'Preparando JSON del lote seleccionado…';
        try {
            const data = await post('seo_dependiente_entrenador_lab_export', { batch_key: key });
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            downloadBlob(blob, data.filename || ('dependiente-laboratorio-' + key + '.json'));
            if (labStatus) labStatus.textContent = 'JSON del lote descargado.';
        } catch (error) {
            if (labStatus) labStatus.textContent = 'No se pudo descargar el JSON: ' + error.message;
        } finally {
            setBusy(false);
        }
    }

    async function startKnowledgeUpdate() {
        if (busy || !updateStartButton) return;
        setBusy(true);
        if (updateStatus) updateStatus.textContent = 'Entregando la actualización al Gestor de workers…';
        try {
            await post('seo_dependiente_actualizacion_start', {});
            if (updateStatus) updateStatus.textContent = 'Actualización encolada. Los módulos avanzarán por el worker compartido.';
            window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (error) {
            if (updateStatus) updateStatus.textContent = 'No se pudo iniciar la actualización: ' + error.message;
            setBusy(false);
        }
    }

    async function exportKnowledgeUpdate() {
        if (busy || !updateExportButton) return;
        setBusy(true);
        if (updateStatus) updateStatus.textContent = 'Preparando informe JSON de Actualización…';
        try {
            const data = await post('seo_dependiente_actualizacion_export', {});
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            downloadBlob(blob, data.filename || 'dependiente-actualizacion.json');
            if (updateStatus) updateStatus.textContent = 'Informe de Actualización descargado.';
        } catch (error) {
            if (updateStatus) updateStatus.textContent = 'No se pudo descargar el informe: ' + error.message;
        } finally {
            setBusy(false);
        }
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    }

    function requestedLessonKey(event, dataKey) {
        const button = event && event.currentTarget ? event.currentTarget : null;
        return button && button.dataset && button.dataset[dataKey]
            ? String(button.dataset[dataKey])
            : lessonKey;
    }

    async function exportLesson(event) {
        const requested = requestedLessonKey(event, 'trainerExportLessonKey');
        if (busy || !requested) return;
        setBusy(true);
        if (runStatus) runStatus.textContent = 'Preparando informe completo de la lección…';
        try {
            const data = await post('seo_dependiente_entrenador_export_lesson', { lesson_key: requested });
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            downloadBlob(blob, data.filename || ('dependiente-academia-' + requested + '.json'));
            if (runStatus) runStatus.textContent = 'Informe completo descargado.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'No se pudo descargar el informe: ' + error.message;
            else window.alert('No se pudo descargar el informe: ' + error.message);
        } finally {
            setBusy(false);
        }
    }

    async function exportProgress(event) {
        const requested = requestedLessonKey(event, 'trainerExportProgressKey');
        if (busy || !requested) return;
        setBusy(true);
        if (runStatus) runStatus.textContent = 'Calculando evolución de la lección…';
        try {
            const data = await post('seo_dependiente_entrenador_export_progress', { lesson_key: requested });
            const blob = new Blob([JSON.stringify(data.document || {}, null, 2)], { type: 'application/json;charset=utf-8' });
            downloadBlob(blob, data.filename || ('dependiente-academia-progreso-' + requested + '.json'));
            if (runStatus) runStatus.textContent = 'Informe de progreso descargado.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'No se pudo descargar el progreso: ' + error.message;
            else window.alert('No se pudo descargar el progreso: ' + error.message);
        } finally {
            setBusy(false);
        }
    }

    function filenameFromDisposition(value, fallback) {
        const match = String(value || '').match(/filename\*?=(?:UTF-8''|\")?([^\";]+)/i);
        return match && match[1] ? decodeURIComponent(match[1].trim()) : fallback;
    }

    async function exportCourse() {
        if (busy || !exportCourseButton) return;
        setBusy(true);
        if (runStatus) runStatus.textContent = 'Preparando informe completo de Academia…';
        try {
            const body = new URLSearchParams({
                action: 'seo_dependiente_entrenador_export_course',
                nonce: config.nonce || ''
            });
            const response = await fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            if (!response.ok) {
                const raw = await response.text();
                let message = 'HTTP ' + response.status + ': no se pudo exportar el curso.';
                try {
                    const payload = JSON.parse(raw);
                    if (payload && payload.data && payload.data.message) message = payload.data.message;
                } catch (ignore) {}
                throw new Error(message);
            }
            const blob = await response.blob();
            const filename = filenameFromDisposition(
                response.headers.get('Content-Disposition'),
                'dependiente-academia-curso-completo.json'
            );
            downloadBlob(blob, filename);
            if (runStatus) runStatus.textContent = 'Informe completo de Academia descargado.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'No se pudo descargar el curso completo: ' + error.message;
            window.alert('No se pudo descargar el curso completo: ' + error.message);
        } finally {
            setBusy(false);
        }
    }

    prepareButton && prepareButton.addEventListener('click', prepareLesson);
    runModuleButton && runModuleButton.addEventListener('click', runModule);
    exportLessonButtons.forEach(function (button) { button.addEventListener('click', exportLesson); });
    exportProgressButtons.forEach(function (button) { button.addEventListener('click', exportProgress); });
    exportCourseButton && exportCourseButton.addEventListener('click', exportCourse);
    autoButton && autoButton.addEventListener('click', function () { setTrainingMode('auto'); });
    manualButton && manualButton.addEventListener('click', function () { setTrainingMode('manual'); });
    stopButton && stopButton.addEventListener('click', function () {
        if (window.confirm('¿Detener la formación? Se conservará el progreso actual y no se procesarán más lotes hasta reanudar.')) {
            setTrainingMode('stop');
        }
    });
    labImportButton && labImportButton.addEventListener('click', importLabBatch);
    labRescanButton && labRescanButton.addEventListener('click', rescanLabFailures);
    labExportButton && labExportButton.addEventListener('click', exportLabBatch);
    labReportButtons.forEach(function (button) { button.addEventListener('click', showLabLearningReport); });
    labRowExportButtons.forEach(function (button) { button.addEventListener('click', exportLabBatchByKey); });
    updateStartButton && updateStartButton.addEventListener('click', startKnowledgeUpdate);
    updateExportButton && updateExportButton.addEventListener('click', exportKnowledgeUpdate);

    setBusy(false);
    if (autoRunning) window.setTimeout(pollAutomation, 1500);
}());
