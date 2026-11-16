(function () {
    'use strict';
    var panel = document.getElementById('tcs-module-audit');
    var config = window.tcsModuleAudit;
    if (!panel || !config) { return; }
    var progress = document.getElementById('tcs-module-progress');
    var result = document.getElementById('tcs-module-report');
    var speed = document.getElementById('tcs-module-speed');
    var i18n = config.i18n || {};
    function t(key, fallback) { return i18n[key] || fallback; }
    var current = '';
    var token = 0;
    var timer = null;
    function rowMessage(module, message) {
        var cell = document.querySelector('[data-tcs-module-functional="' + module + '"]');
        if (cell) { cell.textContent = message; }
    }
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
            .then(function (response) {
                return response.text().then(function (raw) {
                    var data;
                    try { data = JSON.parse(raw); }
                    catch (error) {
                        throw new Error(t('invalid_json', 'Invalid server response (PHP notice or HTML). Check the PHP error log.') + ' HTTP ' + response.status);
                    }
                    if (!response.ok && (!data || data.success !== false)) {
                        throw new Error(t('request_failed', 'Unable to execute the check.') + ' HTTP ' + response.status);
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error((data.data && data.data.message) || t('request_failed', 'Unable to execute the check.'));
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
            ' · ' + t('functions', 'Functions') + ': ' + (f.done || 0) + '/' + (f.total || 0) +
            ' (' + (totals.fail || 0) + ' ' + t('failures', 'failures') + ', ' + (totals.skip || 0) + ' ' + t('skipped', 'skipped') + ', ' + (totals.warning || 0) + ' ' + t('warnings', 'warnings') + ')' +
            ' · ' + t('code', 'Code') + ': ' + (c.done || 0) + '/' + (c.total || 0) +
            ' (' + (c.errors || 0) + ' ' + t('errors', 'errors') + ', ' + (c.warnings || 0) + ' ' + t('warnings', 'warnings') + ')' +
            (state.error ? ' · ' + state.error : '');
        var fcell = document.querySelector('[data-tcs-module-functional="' + state.plugin + '"]');
        var ccell = document.querySelector('[data-tcs-module-code="' + state.plugin + '"]');
        if (fcell) { fcell.textContent = f.status === 'basic_only' ?
            t('basic_only', 'Basic only: ') + (totals.fail || 0) + ' ' + t('failures', 'failures') :
            (f.done || 0) + '/' + (f.total || 0) + ': ' + (totals.fail || 0) + ' ' + t('failures', 'failures'); }
        if (ccell) { ccell.textContent = (c.done || 0) + '/' + (c.total || 0) + ': ' +
            (c.errors || 0) + ' ' + t('errors', 'errors') + ', ' + (c.warnings || 0) + ' ' + t('warnings', 'warnings'); }
        var toggle = document.querySelector('[data-tcs-module-toggle="' + state.plugin + '"]');
        if (toggle) {
            toggle.textContent = state.status === 'running' ? t('stop', 'Stop') : t('resume', 'Resume');
            toggle.disabled = state.status === 'completed' || state.status === 'not_run';
        }
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
            text('p', label(item.message, t('no_details', 'No details available.')), row);
        } else {
            text('strong', label(item.severity, item.status || type) +
                ' · ' + label(item.file, '') +
                (item.line == null ? '' : ':' + item.line) +
                ' · ' + label(item.rule, item.group || ''), row);
            text('p', label(item.description, t('review_required', 'Review required.')), row);
            if (item.recommendation) { text('p', t('recommendation', 'Recommendation: ') + item.recommendation, row); }
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
        if (!items.length) { text('p', t('none_recorded', 'No results in this category.'), details); return; }
        var list = document.createElement('ol');
        details.appendChild(list);
        items.forEach(function (item) { renderRow(item, list, type); });
    }
    function renderReport(report) {
        result.hidden = false;
        result.textContent = '';
        if (!report || !report.summary) {
            text('p', t('not_run', 'No report exists for this plugin yet.'), result);
            return;
        }
        var title = text('h3', t('results_of', 'Results for ') + label(report.name, report.plugin), result);
        title.setAttribute('tabindex', '-1');
        var summary = report.summary;
        text('p', t('status', 'Status: ') + label(summary.status, 'pendiente') +
            t('date', ' · Date: ') + label(report.executed_at, '—'), result);
        if (summary.note) { text('p', summary.note, result); }
        var link = text('a', t('download_json', 'Download complete JSON for this plugin'), result);
        link.href = config.export + '&module=' + encodeURIComponent(report.plugin);
        link.className = 'button button-secondary';
        var events = report.execution_log || [];
        section(t('execution_log', 'Execution log'), events.map(function (event) {
            return { status: label(event.action, 'step'), group: event.phase,
                label: event.at, message: event.message || event.phase + ': ' +
                    label(event.functional_done, '0') + ' / ' + label(event.code_done, '0') };
        }), 'functional', true);
        var functional = report.functional_tests || [];
        section(t('functional_failures', 'Functional failures'), functional.filter(function (x) { return x.status === 'fail'; }), 'functional', true);
        section(t('warnings', 'Warnings') + ' · ' + t('functions', 'Functions'), functional.filter(function (x) { return x.status === 'warning'; }), 'functional', false);
        section(t('skipped_checks', 'Skipped checks'), functional.filter(function (x) { return x.status === 'skip'; }), 'functional', false);
        section(t('passed_checks', 'Passed checks'), functional.filter(function (x) { return x.status === 'pass'; }), 'functional', false);
        var code = report.code_findings || {};
        section(t('code_errors', 'Code errors'), code.errors || [], 'code', true);
        section(t('code_warnings', 'Code warnings (manual review)'), code.warnings || [], 'code', true);
        section(t('unchecked', 'Unchecked files'), code.unchecked || [], 'code', false);
        text('p', t('disclaimer', 'Local code review is not the official WordPress Plugin Check.'), result);
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        title.focus({ preventScroll: true });
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
                progress.textContent = error.message + t('can_resume', ' · You can resume this check.');
                rowMessage(current, error.message);
            });
        }, Number(state.delay_ms) || 2500);
    }
    document.addEventListener('click', function (event) {
        var start = event.target.closest('[data-tcs-module-start]');
        var report = event.target.closest('[data-tcs-module-report]');
        var toggle = event.target.closest('[data-tcs-module-toggle]');
        if (toggle) {
            var module = toggle.getAttribute('data-tcs-module-toggle');
            current = module;
            clearTimer();
            request(module, 'status').then(function (existing) {
                if (existing.status === 'running') { return request(module, 'stop'); }
                if (existing.status === 'paused') { return request(module, 'resume'); }
                return existing;
            }).then(function (state) {
                show(state);
                if (state.status === 'running') { next(state, token); }
            }).catch(function (error) {
                progress.textContent = error.message;
                rowMessage(module, error.message);
            });
            return;
        }
        if (report) { view(report.getAttribute('data-tcs-module-report')); return; }
        if (!start) { return; }
        current = start.getAttribute('data-tcs-module-start');
        clearTimer();
        result.hidden = true;
        progress.textContent = t('preparing', 'Preparing check for ') + current + '…';
        rowMessage(current, t('preparing', 'Preparing check for ') + current + '…');
        var mine = token;
        request(current, 'start').then(function (state) {
            show(state);
            next(state, mine);
        }).catch(function (error) {
            progress.textContent = error.message;
            rowMessage(current, error.message);
        });
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
    // A previously started run must remain stoppable after navigation or reload.
    if (config.initial && config.initial.plugin) {
        current = config.initial.plugin;
        show(config.initial);
        if (config.initial.status === 'running') {
            progress.textContent += t('resume_hint', ' · Waiting for browser. Press Resume to continue.');
        }
    }
}());
