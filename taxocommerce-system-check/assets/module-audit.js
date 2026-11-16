(function () {
    'use strict';
    var panel = document.getElementById('tcs-module-audit');
    var config = window.tcsModuleAudit;
    if (!panel || !config) { return; }
    var progress = document.getElementById('tcs-module-progress');
    var result = document.getElementById('tcs-module-report');
    var speed = document.getElementById('tcs-module-speed');
    var current = '';
    var token = 0;
    var timer = null;
    function text(tag, content, parent) {
        var element = document.createElement(tag);
        element.textContent = String(content == null ? '' : content);
        parent.appendChild(element);
        return element;
    }
    function label(value, fallback) { return value == null || value === '' ? fallback : String(value); }
    function request(module, operation) {
        var body = new URLSearchParams();
        body.set('action', 'tcs_module_audit');
        body.set('nonce', config.nonce);
        body.set('module', module);
        body.set('operation', operation);
        body.set('speed', speed.value);
        return fetch(config.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error((data.data && data.data.message) || 'No se pudo ejecutar el chequeo.');
                }
                return data.data;
            });
    }
    function show(state) {
        if (!state) { return; }
        var f = state.functional || {};
        var c = state.code || {};
        var totals = f.totals || {};
        progress.textContent = label(state.name, state.plugin) + ': ' + label(state.status, '') +
            ' · Funcionalidades: ' + (f.done || 0) + '/' + (f.total || 0) +
            ' (' + (totals.fail || 0) + ' fallos, ' + (totals.skip || 0) + ' omitidos)' +
            ' · Código: ' + (c.done || 0) + '/' + (c.total || 0) +
            ' (' + (c.errors || 0) + ' errores, ' + (c.warnings || 0) + ' avisos)' +
            (state.error ? ' · ' + state.error : '');
        var fcell = document.querySelector('[data-tcs-module-functional="' + state.plugin + '"]');
        var ccell = document.querySelector('[data-tcs-module-code="' + state.plugin + '"]');
        if (fcell) { fcell.textContent = f.status === 'basic_only' ?
            'Solo básico: ' + (totals.fail || 0) + ' fallos' :
            (f.done || 0) + '/' + (f.total || 0) + ': ' + (totals.fail || 0) + ' fallos'; }
        if (ccell) { ccell.textContent = (c.done || 0) + '/' + (c.total || 0) + ': ' +
            (c.errors || 0) + ' errores, ' + (c.warnings || 0) + ' avisos'; }
    }
    function clearTimer() {
        ++token;
        if (timer) { window.clearTimeout(timer); timer = null; }
    }
    function renderRow(item, container, type) {
        var row = text('li', '', container);
        if (type === 'functional') {
            text('strong', label(item.status, 'skip') + ' · ' + label(item.group, '') +
                ' · ' + label(item.label, item.id), row);
            text('p', label(item.message, 'Sin detalle'), row);
        } else {
            text('strong', label(item.severity, item.status || type) +
                ' · ' + label(item.file, '') +
                (item.line == null ? '' : ':' + item.line) +
                ' · ' + label(item.rule, item.group || ''), row);
            text('p', label(item.description, 'Revisión requerida'), row);
            if (item.recommendation) { text('p', 'Sugerencia: ' + item.recommendation, row); }
            else if (item.evidence && Object.keys(item.evidence).length) {
                var evidence = JSON.stringify(item.evidence);
                text('pre', evidence.length > 1600 ? evidence.slice(0, 1600) + '…' : evidence, row);
            }
        }
    }
    function section(title, items, type, open) {
        var details = document.createElement('details');
        details.open = !!open;
        result.appendChild(details);
        text('summary', title + ' (' + items.length + ')', details);
        if (!items.length) { text('p', 'No se han registrado resultados en esta categoría.', details); return; }
        var list = document.createElement('ol');
        details.appendChild(list);
        items.forEach(function (item) { renderRow(item, list, type); });
    }
    function renderReport(report) {
        result.hidden = false;
        result.textContent = '';
        if (!report || !report.summary) {
            text('p', 'Todavía no existe un informe para el plugin seleccionado.', result);
            return;
        }
        var title = text('h3', 'Resultados de ' + label(report.name, report.plugin), result);
        title.setAttribute('tabindex', '-1');
        var summary = report.summary;
        text('p', 'Estado: ' + label(summary.status, 'pendiente') +
            ' · Fecha: ' + label(report.executed_at, '—'), result);
        if (summary.note) { text('p', summary.note, result); }
        var link = text('a', 'Descargar JSON completo de este plugin', result);
        link.href = config.export + '&module=' + encodeURIComponent(report.plugin);
        link.className = 'button button-secondary';
        var functional = report.functional_tests || [];
        section('Fallos funcionales', functional.filter(function (x) { return x.status === 'fail'; }), 'functional', true);
        section('Pruebas omitidas', functional.filter(function (x) { return x.status === 'skip'; }), 'functional', false);
        section('Pruebas correctas', functional.filter(function (x) { return x.status === 'pass'; }), 'functional', false);
        var code = report.code_findings || {};
        section('Errores de código', code.errors || [], 'code', true);
        section('Avisos de código (revisión manual)', code.warnings || [], 'code', true);
        section('Archivos sin comprobar', code.unchecked || [], 'code', false);
        text('p', 'El análisis local de código no sustituye el WordPress Plugin Check oficial.', result);
    }
    function view(module) {
        current = module;
        request(module, 'report').then(renderReport).catch(function (error) {
            result.hidden = false;
            result.textContent = '';
            text('p', error.message, result);
        });
    }
    function next(state, mine) {
        if (mine !== token || state.status !== 'running') {
            if (state.status === 'completed' && mine === token) { view(current); }
            return;
        }
        timer = window.setTimeout(function () {
            if (mine !== token) { return; }
            request(current, 'step').then(function (updated) {
                show(updated);
                next(updated, mine);
            }).catch(function (error) {
                progress.textContent = error.message + ' · Puedes reanudar el chequeo.';
            });
        }, Number(state.delay_ms) || 2500);
    }
    document.addEventListener('click', function (event) {
        var start = event.target.closest('[data-tcs-module-start]');
        var report = event.target.closest('[data-tcs-module-report]');
        if (report) { view(report.getAttribute('data-tcs-module-report')); return; }
        if (!start) { return; }
        current = start.getAttribute('data-tcs-module-start');
        clearTimer();
        result.hidden = true;
        progress.textContent = 'Preparando chequeo de ' + current + '…';
        var mine = token;
        request(current, 'start').then(function (state) {
            show(state);
            next(state, mine);
        }).catch(function (error) { progress.textContent = error.message; });
    });
    panel.addEventListener('click', function (event) {
        var control = event.target.closest('[data-tcs-module-op]');
        if (!control || !current) { return; }
        clearTimer();
        var op = control.getAttribute('data-tcs-module-op');
        var mine = token;
        request(current, op).then(function (state) {
            show(state);
            if (op === 'resume') { next(state, mine); }
        }).catch(function (error) { progress.textContent = error.message; });
    });
}());
