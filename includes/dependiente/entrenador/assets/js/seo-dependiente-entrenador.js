(function () {
    'use strict';

    const config = window.SEODependienteEntrenador || {};
    const root = document.querySelector('[data-trainer-root]');
    if (!root) return;

    const addButton = root.querySelector('[data-trainer-add]');
    const addStatus = root.querySelector('[data-trainer-add-status]');
    const textarea = root.querySelector('[data-trainer-questions]');
    const defaultType = root.querySelector('[data-trainer-default-type]');
    const defaultMode = root.querySelector('[data-trainer-default-mode]');
    const filterType = root.querySelector('[data-trainer-filter-type]');
    const selectAll = root.querySelector('[data-trainer-select-all]');
    const runListButton = root.querySelector('[data-trainer-run-list]');
    const runSelectedButton = root.querySelector('[data-trainer-run-selected]');
    const deleteButton = root.querySelector('[data-trainer-delete]');
    const clearRunsButton = root.querySelector('[data-trainer-clear-runs]');
    const exportJsonButton = root.querySelector('[data-trainer-export-json]');
    const runStatus = root.querySelector('[data-trainer-run-status]');
    const progress = root.querySelector('[data-trainer-progress]');
    const progressBar = root.querySelector('[data-trainer-progress-bar]');
    const runBody = root.querySelector('[data-trainer-run-body]');
    const batchLabel = root.querySelector('[data-trainer-batch-label]');
    const breakdown = root.querySelector('[data-trainer-breakdown]');

    let running = false;
    let currentBatchUuid = root.dataset.trainerCurrentBatch || '';

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
            const error = new Error((cause && cause.message) || 'Failed to fetch');
            error.transient = true;
            error.httpStatus = 0;
            throw error;
        }

        const raw = await response.text();
        let payload = null;
        try {
            payload = raw ? JSON.parse(raw) : null;
        } catch (cause) {
            const error = new Error('Respuesta HTTP no valida (' + response.status + ').');
            error.transient = response.status >= 500 || response.status === 429 || response.status === 408;
            error.httpStatus = response.status;
            throw error;
        }

        if (!response.ok || !payload || !payload.success) {
            const message = payload && payload.data && payload.data.message
                ? payload.data.message
                : ('HTTP ' + response.status + ': no se pudo completar la operacion.');
            const error = new Error(message);
            error.transient = response.status >= 500 || response.status === 429 || response.status === 408;
            error.httpStatus = response.status;
            throw error;
        }
        return payload.data || {};
    }

    function sleep(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, Math.max(0, ms)); });
    }

    function createBatchUuid() {
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

    function questionRows() {
        return Array.from(root.querySelectorAll('[data-trainer-question-row]'));
    }

    function selectedIds() {
        return Array.from(root.querySelectorAll('[data-trainer-question-check]:checked')).map(function (input) {
            return Number(input.value || 0);
        }).filter(Boolean);
    }

    function setRunning(value) {
        running = !!value;
        [addButton, runListButton, runSelectedButton, deleteButton].forEach(function (button) {
            if (button) button.disabled = running;
        });
        if (exportJsonButton) exportJsonButton.disabled = running || !currentBatchUuid;
        if (filterType) filterType.disabled = running;
        if (selectAll) selectAll.disabled = running;
    }

    function setProgress(done, total) {
        if (!progress || !progressBar) return;
        progress.hidden = false;
        const percent = total > 0 ? Math.max(0, Math.min(100, Math.round((done / total) * 100))) : 0;
        progressBar.style.width = percent + '%';
    }

    function updateKpis(summary) {
        Object.keys(summary || {}).forEach(function (key) {
            const el = root.querySelector('[data-trainer-kpi="' + key + '"]');
            if (el) el.textContent = new Intl.NumberFormat().format(Number(summary[key] || 0));
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function typeLabel(type) {
        const labels = {
            need: 'Necesidad', product: 'Producto concreto', compatibility: 'Compatibilidad',
            symptom: 'Síntoma', use_case: 'Uso / tarea', comparison: 'Comparación',
            colloquial: 'Lenguaje coloquial', typo: 'Error ortográfico', ambiguous: 'Ambigua', other: 'Otra'
        };
        return labels[type] || labels.other;
    }

    function renderBreakdown(items) {
        if (!breakdown) return;
        if (!items || !items.length) {
            breakdown.innerHTML = '<p class="description">El desglose por tipo aparecerá después de ejecutar preguntas.</p>';
            return;
        }
        const rows = items.map(function (item) {
            return '<tr><td>' + escapeHtml(typeLabel(item.question_type)) + '</td>' +
                '<td>' + Number(item.launched || 0) + '</td>' +
                '<td>' + Number(item.answered || 0) + '</td>' +
                '<td>' + Number(item.with_results || 0) + '</td>' +
                '<td>' + Number(item.without_results || 0) + '</td>' +
                '<td>' + Number(item.returned_results || 0) + '</td></tr>';
        }).join('');
        breakdown.innerHTML = '<div class="seo-dependiente-trainer__breakdown"><h3>Por tipo de pregunta</h3>' +
            '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Lanzadas</th><th>Contestadas</th><th>Con resultados</th><th>Sin resultados</th><th>Resultados devueltos</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function renderRunRow(row) {
        const results = Array.isArray(row.top_results) ? row.top_results : [];
        const meta = row.response_meta || {};
        const clarification = meta.clarification || {};
        let status = '<span class="seo-dependiente-trainer__status is-ok">Contestada</span>';
        if (row.status === 'error') {
            status = '<span class="seo-dependiente-trainer__status is-error">Error</span>' + (row.error_message ? '<div class="description">' + escapeHtml(row.error_message) + '</div>' : '');
        } else if (Number(row.returned_count || 0) === 0) {
            status = '<span class="seo-dependiente-trainer__status is-empty">Contestada · sin resultados</span>';
        }
        const resultHtml = results.length ? '<ol class="seo-dependiente-trainer__answer-list">' + results.map(function (result) {
            const reasons = Array.isArray(result.reasons) && result.reasons.length ? '<span>' + escapeHtml(result.reasons.join(' · ')) + '</span>' : '';
            return '<li><strong>' + escapeHtml(result.title || '') + '</strong>' + reasons + '</li>';
        }).join('') + '</ol>' : (row.status === 'error' ? '' : '<span class="description">No devolvió productos.</span>');
        const clarificationHtml = clarification.question ? '<div class="seo-dependiente-trainer__clarification"><strong>Aclaración:</strong> ' + escapeHtml(clarification.question) + '</div>' : '';

        return '<tr>' +
            '<td><span class="seo-dependiente-trainer__type">' + escapeHtml(typeLabel(row.question_type)) + '</span><br><code>' + escapeHtml(row.mode) + '</code></td>' +
            '<td><strong>' + escapeHtml(row.question) + '</strong>' + (row.search_strategy ? '<div class="description">Estrategia: <code>' + escapeHtml(row.search_strategy) + '</code></div>' : '') + '</td>' +
            '<td>' + status + '</td>' +
            '<td><strong>' + Number(row.returned_count || 0) + '</strong> devueltos<br><span class="description">' + Number(row.result_count || 0) + ' coincidencias</span></td>' +
            '<td>' + resultHtml + clarificationHtml + '</td>' +
            '</tr>';
    }

    function appendRunRows(rows, first) {
        if (!runBody) return;
        if (first) runBody.innerHTML = '';
        (rows || []).forEach(function (row) {
            runBody.insertAdjacentHTML('beforeend', renderRunRow(row));
        });
        if (!runBody.children.length) {
            runBody.innerHTML = '<tr data-trainer-run-empty><td colspan="5">No hay respuestas del Entrenador todavía.</td></tr>';
        }
    }

    async function addQuestions() {
        if (!textarea || !textarea.value.trim()) {
            if (addStatus) addStatus.textContent = 'Pega al menos una pregunta.';
            return;
        }
        setRunning(true);
        if (addStatus) addStatus.textContent = 'Guardando…';
        try {
            const data = await post('seo_dependiente_entrenador_add_questions', {
                raw: textarea.value,
                default_type: defaultType ? defaultType.value : 'other',
                default_mode: defaultMode ? defaultMode.value : 'need'
            });
            if (addStatus) addStatus.textContent = data.message || 'Preguntas guardadas.';
            window.location.reload();
        } catch (error) {
            if (addStatus) addStatus.textContent = error.message;
            setRunning(false);
        }
    }

    async function deleteQuestions() {
        const ids = selectedIds();
        if (!ids.length) {
            if (runStatus) runStatus.textContent = 'Selecciona preguntas para eliminar.';
            return;
        }
        if (!window.confirm('¿Eliminar ' + ids.length + ' preguntas del banco? El historial de ejecuciones se conservará.')) return;
        setRunning(true);
        try {
            await post('seo_dependiente_entrenador_delete_questions', { ids: ids.join(',') });
            window.location.reload();
        } catch (error) {
            if (runStatus) runStatus.textContent = error.message;
            setRunning(false);
        }
    }

    async function clearRuns() {
        if (!window.confirm('¿Borrar todo el historial de ejecuciones del Entrenador? El banco de preguntas se conservará.')) return;
        setRunning(true);
        try {
            await post('seo_dependiente_entrenador_clear_runs');
            window.location.reload();
        } catch (error) {
            if (runStatus) runStatus.textContent = error.message;
            setRunning(false);
        }
    }

    async function downloadJson() {
        if (!currentBatchUuid) {
            if (runStatus) runStatus.textContent = 'Todavía no hay una ejecución para descargar.';
            return;
        }

        setRunning(true);
        if (runStatus) runStatus.textContent = 'Preparando JSON…';
        try {
            const data = await post('seo_dependiente_entrenador_export_json', {
                batch_uuid: currentBatchUuid
            });
            const documentData = data.document || {};
            const blob = new Blob([JSON.stringify(documentData, null, 2)], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = data.filename || ('dependiente-entrenador-' + currentBatchUuid.slice(0, 8) + '.json');
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            if (runStatus) runStatus.textContent = 'JSON descargado. Puedes adjuntarlo en ChatGPT para analizar el lote.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'No se pudo descargar el JSON: ' + error.message;
        } finally {
            setRunning(false);
        }
    }

    async function runScope(scope, ids) {
        if (running) return;
        setRunning(true);

        const batchMin = Math.max(1, Number(config.batchMin || 1));
        const batchMax = Math.max(batchMin, Number(config.batchMax || 4));
        const fastSeconds = Math.max(0.5, Number(config.fastSeconds || 2.5));
        const slowSeconds = Math.max(fastSeconds + 0.5, Number(config.slowSeconds || 7));
        const hardSeconds = Math.max(slowSeconds + 1, Number(config.hardSeconds || 14));
        const maxRetries = Math.max(1, Number(config.maxRetries || 6));

        // Generamos el UUID antes de la primera peticion. Si el servidor termina
        // un lote pero la respuesta se pierde, el reintento usa el mismo UUID y
        // PHP reutiliza las preguntas ya guardadas.
        let batchUuid = createBatchUuid();
        let batchSize = Math.max(batchMin, Math.min(batchMax, Number(config.batchSize || 1)));
        let offset = 0;
        let selectedOffset = 0;
        let completed = 0;
        let firstRows = true;
        let fastStreak = 0;
        let retries = 0;
        const selectedType = filterType ? filterType.value : '';

        if (scope === 'ids' && !ids.length) {
            if (runStatus) runStatus.textContent = 'Selecciona al menos una pregunta.';
            setRunning(false);
            return;
        }

        const totalExpected = scope === 'ids' ? ids.length : 0;
        if (runStatus) runStatus.textContent = 'Ejecutando de forma adaptativa...';
        setProgress(0, totalExpected || 1);

        try {
            while (true) {
                const chunk = scope === 'ids' ? ids.slice(selectedOffset, selectedOffset + batchSize) : [];
                const startedAt = performance.now();
                let data;

                try {
                    data = await post('seo_dependiente_entrenador_run_batch', {
                        batch_uuid: batchUuid,
                        scope: scope,
                        type: scope === 'type' ? selectedType : '',
                        offset: offset,
                        ids: chunk.join(','),
                        batch_size: batchSize
                    });
                    retries = 0;
                } catch (error) {
                    const transient = !!error.transient || error.httpStatus === 0;
                    if (!transient || retries >= maxRetries) {
                        throw error;
                    }
                    retries += 1;
                    batchSize = batchMin;
                    fastStreak = 0;
                    const wait = retryDelay(retries);
                    if (runStatus) {
                        runStatus.textContent = 'Problema temporal de conexion. Reintento ' + retries + '/' + maxRetries +
                            ' en ' + Math.round(wait / 1000) + ' s. Lote reducido a ' + batchSize + '.';
                    }
                    await sleep(wait);
                    continue;
                }

                const duration = Math.max(0, (performance.now() - startedAt) / 1000);
                batchUuid = data.batch_uuid || batchUuid;
                if (batchUuid) {
                    currentBatchUuid = batchUuid;
                    root.dataset.trainerCurrentBatch = batchUuid;
                }

                const processed = Number(data.processed || 0);
                completed += processed;
                offset = Number(data.next_offset || offset + processed);
                if (scope === 'ids') selectedOffset += processed;

                const total = scope === 'ids' ? ids.length : Number(data.total_scope || 0);
                setProgress(completed, total || completed || 1);
                updateKpis(data.summary || {});
                renderBreakdown(data.breakdown || []);
                appendRunRows(data.rows || [], firstRows);
                firstRows = false;
                if (batchLabel && batchUuid) batchLabel.textContent = 'Lote ' + batchUuid.slice(0, 8);

                let delay = 500;
                if (duration >= hardSeconds) {
                    batchSize = batchMin;
                    fastStreak = 0;
                    delay = 5000;
                } else if (duration >= slowSeconds) {
                    batchSize = Math.max(batchMin, Math.floor(batchSize / 2));
                    fastStreak = 0;
                    delay = 2000;
                } else if (duration <= fastSeconds) {
                    fastStreak += 1;
                    if (fastStreak >= 2 && batchSize < batchMax) {
                        batchSize += 1;
                        fastStreak = 0;
                    }
                    delay = 250;
                } else {
                    fastStreak = 0;
                    delay = 750;
                }

                if (runStatus) {
                    runStatus.textContent = completed + ' de ' + total + ' preguntas procesadas · ' +
                        duration.toFixed(1) + ' s ultimo lote · siguiente lote: ' + batchSize + '.';
                }

                if (scope === 'ids') {
                    if (selectedOffset >= ids.length || processed === 0) break;
                } else if (data.done) {
                    break;
                }

                await sleep(delay);
            }
            if (runStatus) runStatus.textContent = 'Ejecucion terminada: ' + completed + ' preguntas.';
        } catch (error) {
            if (runStatus) {
                runStatus.textContent = 'Ejecucion detenida tras reintentos: ' + error.message +
                    '. Lo ya procesado queda guardado.';
            }
        } finally {
            setRunning(false);
        }
    }

    function applyFilter() {
        const type = filterType ? filterType.value : '';
        questionRows().forEach(function (row) {
            row.hidden = !!type && row.dataset.questionType !== type;
            if (row.hidden) {
                const input = row.querySelector('[data-trainer-question-check]');
                if (input) input.checked = false;
            }
        });
        if (selectAll) selectAll.checked = false;
    }

    addButton && addButton.addEventListener('click', addQuestions);
    deleteButton && deleteButton.addEventListener('click', deleteQuestions);
    clearRunsButton && clearRunsButton.addEventListener('click', clearRuns);
    exportJsonButton && exportJsonButton.addEventListener('click', downloadJson);
    filterType && filterType.addEventListener('change', applyFilter);
    runListButton && runListButton.addEventListener('click', function () {
        const type = filterType ? filterType.value : '';
        runScope(type ? 'type' : 'all', []);
    });
    runSelectedButton && runSelectedButton.addEventListener('click', function () {
        runScope('ids', selectedIds());
    });
    if (exportJsonButton) exportJsonButton.disabled = !currentBatchUuid;

    selectAll && selectAll.addEventListener('change', function () {
        questionRows().forEach(function (row) {
            if (row.hidden) return;
            const input = row.querySelector('[data-trainer-question-check]');
            if (input) input.checked = selectAll.checked;
        });
    });
}());
