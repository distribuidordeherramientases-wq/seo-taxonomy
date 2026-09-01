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
    const runStatus = root.querySelector('[data-trainer-run-status]');
    const progress = root.querySelector('[data-trainer-progress]');
    const progressBar = root.querySelector('[data-trainer-progress-bar]');
    const runBody = root.querySelector('[data-trainer-run-body]');
    const batchLabel = root.querySelector('[data-trainer-batch-label]');
    const breakdown = root.querySelector('[data-trainer-breakdown]');

    let running = false;

    async function post(action, data) {
        const body = new URLSearchParams(Object.assign({ action, nonce: config.nonce || '' }, data || {}));
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
        return payload.data || {};
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

    async function runScope(scope, ids) {
        if (running) return;
        setRunning(true);
        let batchUuid = '';
        let offset = 0;
        let completed = 0;
        let firstRows = true;
        const selectedType = filterType ? filterType.value : '';
        let selectedChunks = [];
        if (scope === 'ids') {
            const size = Math.max(1, Number(config.batchSize || 6));
            for (let i = 0; i < ids.length; i += size) selectedChunks.push(ids.slice(i, i + size));
            if (!selectedChunks.length) {
                if (runStatus) runStatus.textContent = 'Selecciona al menos una pregunta.';
                setRunning(false);
                return;
            }
        }

        if (runStatus) runStatus.textContent = 'Ejecutando preguntas…';
        setProgress(0, scope === 'ids' ? ids.length : 1);

        try {
            let chunkIndex = 0;
            while (true) {
                const chunk = scope === 'ids' ? selectedChunks[chunkIndex] : [];
                const data = await post('seo_dependiente_entrenador_run_batch', {
                    batch_uuid: batchUuid,
                    scope: scope,
                    type: scope === 'type' ? selectedType : '',
                    offset: offset,
                    ids: chunk.join(',')
                });
                batchUuid = data.batch_uuid || batchUuid;
                const processed = Number(data.processed || 0);
                completed += processed;
                offset = Number(data.next_offset || offset + processed);
                const total = scope === 'ids' ? ids.length : Number(data.total_scope || 0);
                setProgress(completed, total || completed || 1);
                updateKpis(data.summary || {});
                renderBreakdown(data.breakdown || []);
                appendRunRows(data.rows || [], firstRows);
                firstRows = false;
                if (batchLabel && batchUuid) batchLabel.textContent = 'Lote ' + batchUuid.slice(0, 8);
                if (runStatus) runStatus.textContent = completed + ' de ' + total + ' preguntas procesadas.';

                if (scope === 'ids') {
                    chunkIndex += 1;
                    if (chunkIndex >= selectedChunks.length) break;
                } else if (data.done) {
                    break;
                }
            }
            if (runStatus) runStatus.textContent = 'Ejecución terminada: ' + completed + ' preguntas.';
        } catch (error) {
            if (runStatus) runStatus.textContent = 'Ejecución detenida: ' + error.message;
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
    filterType && filterType.addEventListener('change', applyFilter);
    runListButton && runListButton.addEventListener('click', function () {
        const type = filterType ? filterType.value : '';
        runScope(type ? 'type' : 'all', []);
    });
    runSelectedButton && runSelectedButton.addEventListener('click', function () {
        runScope('ids', selectedIds());
    });
    selectAll && selectAll.addEventListener('change', function () {
        questionRows().forEach(function (row) {
            if (row.hidden) return;
            const input = row.querySelector('[data-trainer-question-check]');
            if (input) input.checked = selectAll.checked;
        });
    });
}());
