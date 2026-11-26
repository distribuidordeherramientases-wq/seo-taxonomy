(function () {
    'use strict';
    if (typeof tciepBatch === 'undefined') return;
    var labels = tciepBatch.labels;
    var jobs = [];
    var busy = false;
    var timer = null;
    var delays = [0, 15000, 8000, 4000, 2000, 1000];

    function label(key) { return labels[key] || key; }
    function node(tag, value) {
        var el = document.createElement(tag);
        if (typeof value !== 'undefined') el.textContent = String(value);
        return el;
    }
    function render(job) {
        var s = job.state;
        var box = job.box;
        box.querySelector('.tciep-progress').textContent = label(s.phase) +
            (s.paused ? ' · ' + label('paused') : '') + (s.kind === 'export' ? ' · ' + label('rows') + ': ' + s.rows : '');
        var sample = s.sample || {};
        box.querySelector('.tciep-telemetry').textContent = sample.seconds === undefined ? '' :
            label('cpu') + ': ' + (sample.cpu === null ? '—' : sample.cpu + '%') + ' · ' +
            label('memory') + ': ' + (sample.memory === null ? '—' : sample.memory + '%') + ' · ' +
            label('seconds') + ': ' + sample.seconds + ' · ' + label('next') + ': ' + sample.next;
        var files = box.querySelector('.tciep-files');
        files.textContent = '';
        if (s.files && s.files.length) {
            var table = node('table');
            table.className = 'widefat striped';
            var head = node('thead'), title = node('tr');
            ['file', 'status', 'checked_rows', 'processed_rows', 'saved_rows', 'errors'].forEach(function (k) {
                title.appendChild(node('th', label(k)));
            });
            head.appendChild(title);
            table.appendChild(head);
            var body = node('tbody');
            s.files.forEach(function (file) {
                var row = node('tr');
                [file.name, label(file.phase), file.checked, file.processed, file.saved, file.errors].forEach(function (value) {
                    row.appendChild(node('td', value));
                });
                body.appendChild(row);
                if ((file.notices && file.notices.length) || (file.ignored && file.ignored.length)) {
                    var detail = node('tr'), cell = node('td');
                    cell.colSpan = 6;
                    if (file.ignored && file.ignored.length) cell.appendChild(node('p', label('ignored') + ': ' + file.ignored.join(', ')));
                    (file.notices || []).forEach(function (notice) { cell.appendChild(node('p', notice)); });
                    detail.appendChild(cell);
                    body.appendChild(detail);
                }
            });
            table.appendChild(body);
            files.appendChild(table);
        }
        var notices = box.querySelector('.tciep-notices');
        notices.textContent = '';
        (s.notices || []).forEach(function (message) { notices.appendChild(node('p', message)); });
        if (job.error) notices.appendChild(node('p', job.error));
        var visible = {
            confirm: s.phase === 'ready' && !s.paused,
            pause: s.active && !s.paused,
            resume: s.active && s.paused,
            clear: !s.active || s.paused,
            speed: s.active
        };
        box.querySelectorAll('[data-op]').forEach(function (button) {
            var op = button.getAttribute('data-op');
            button.hidden = !visible[op];
            button.disabled = !!job.pending || !!job.request;
        });
        box.querySelector('[name="speed"]').disabled = !s.active || !!job.pending || !!job.request;
        var download = box.querySelector('.tciep-download');
        if (download) download.hidden = s.phase !== 'done';
    }

    function schedule(ms) {
        if (timer !== null) window.clearTimeout(timer);
        timer = window.setTimeout(pump, ms);
    }

    function pump() {
        timer = null;
        if (busy) return;
        var now = Date.now();
        var job = jobs.find(function (j) { return !!j.pending; }) ||
            jobs.find(function (j) { return j.state.active && !j.state.paused && !j.localStop && j.due <= now; });
        if (!job) {
            if (jobs.some(function (j) { return j.state.active && !j.state.paused && !j.localStop; })) schedule(500);
            return;
        }
        var op = job.pending || 'step';
        job.pending = null;
        job.request = true;
        job.error = '';
        busy = true;
        render(job);
        var data = new FormData();
        data.append('action', 'tciep_command');
        data.append('nonce', tciepBatch.nonce);
        data.append('kind', job.state.kind);
        data.append('type', job.state.type);
        data.append('token', job.state.token);
        data.append('operation', op);
        data.append('speed', job.box.querySelector('[name="speed"]').value);
        fetch(tciepBatch.endpoint, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (!response.success) {
                    if (response.data && response.data.code === 'busy') {
                        if (op !== 'step') job.pending = op;
                        job.due = Date.now() + 1500;
                        return;
                    }
                    throw new Error(response.data && response.data.message || label('connection'));
                }
                if (op === 'clear') {
                    job.localStop = true;
                    window.location.reload();
                    return;
                }
                job.state = response.data;
                if (op === 'speed') job.box.querySelector('[name="speed"]').value = String(job.state.speed);
                job.localStop = false;
                job.due = Date.now() + (delays[job.state.speed] || 4000);
            })
            .catch(function (error) {
                job.error = error.message || label('connection');
                job.localStop = true;
            })
            .finally(function () {
                job.request = false;
                busy = false;
                render(job);
                schedule(500);
            });
    }

    document.querySelectorAll('.tciep-job[data-job]').forEach(function (box) {
        var state;
        try { state = JSON.parse(box.getAttribute('data-job')); } catch (error) { return; }
        if (!state || !state.token) return;
        var job = { box: box, state: state, due: 0, request: false, pending: null, error: '', localStop: false };
        jobs.push(job);
        box.querySelectorAll('[data-op]').forEach(function (button) {
            button.addEventListener('click', function () {
                var op = button.getAttribute('data-op');
                if (job.request || job.pending) return;
                if (['confirm', 'clear', 'resume'].indexOf(op) !== -1 && !window.confirm(label(op + '_question'))) return;
                job.pending = op;
                job.localStop = false;
                render(job);
                schedule(0);
            });
        });
        render(job);
    });
    if (jobs.length) schedule(0);
}());
